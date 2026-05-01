<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Models\RadiusClient;
use App\Models\RadiusLog;
use App\Models\RadiusSession;
use App\Services\RadiusServerAdapter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RadiusServer extends Command
{
    protected $signature = 'radius:serve {--port=1812}';
    protected $description = 'Start the ND-Hotspot RADIUS Server';

    private $socket;
    private $clients = [];
    private $lastClientRefresh = 0;

    public function handle()
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }

        $port = $this->option('port');
        $this->info("ND-Hotspot RADIUS Server starting on port $port...");

        $this->loadClients();

        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$this->socket) {
            $this->error("Could not create socket: " . socket_strerror(socket_last_error()));
            return;
        }

        // Fix #5: Set socket timeout for graceful shutdown (2 second timeout)
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        if (!socket_bind($this->socket, '0.0.0.0', $port)) {
            $this->error("Could not bind socket to port $port: " . socket_strerror(socket_last_error()));
            return;
        }

        $this->info("Server is listening...");
        $this->logEvent(null, '0.0.0.0', 'Info', 'Success', "Server started on port $port");
        
        // Telegram Alert
        app(\App\Services\TelegramService::class)->sendMessage("🚀 <b>RADIUS SERVER STARTED</b>\nPort: {$port}\nStatus: Listening for NAS requests...");

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $from = '';
            $fromPort = 0;
            $data = '';
            
            if (@socket_recvfrom($this->socket, $data, 1024, 0, $from, $fromPort)) {
                $this->handlePacket($data, $from, $fromPort);
            }
        }
    }

    public function shutdown()
    {
        $this->info("Shutting down RADIUS server...");
        if ($this->socket) {
            socket_close($this->socket);
        }
        
        // Telegram Alert
        app(\App\Services\TelegramService::class)->sendMessage("⚠️ <b>RADIUS SERVER STOPPED</b>\nStatus: Offline\n<i>Please check server logs if this was unexpected.</i>");
        
        exit(0);
    }

    private function loadClients()
    {
        $this->clients = RadiusClient::all()->pluck('shared_secret', 'ip_address')->toArray();
        $this->lastClientRefresh = time();
    }

    private function handlePacket($data, $from, $fromPort)
    {
        // loadClients() Refresh Logic (Cooldown/Cache)
        if (time() - $this->lastClientRefresh > 60 || !isset($this->clients[$from])) {
            $this->loadClients();
        }

        if (!isset($this->clients[$from])) {
            $this->warn("Received packet from unknown client: $from");
            $this->logEvent(null, $from, 'Auth', 'Fail', "Unknown client attempted connection");
            
            // Telegram Alert (Possible intrusion or config error)
            app(\App\Services\TelegramService::class)->sendMessage("🛡️ <b>SECURITY ALERT</b>\nAttempt from UNKNOWN IP: <b>{$from}</b>\nStatus: Rejected by Firewall");
            
            return;
        }

        $secret = $this->clients[$from];
        $radius = new RadiusServerAdapter();
        
        $packet = $radius->decodePacket($data, $secret);
        
        if (!$packet) {
            $this->error("Failed to decode packet from $from (Invalid length or checksum)");
            $this->logEvent(null, $from, 'Auth', 'Fail', "Invalid packet from $from");
            return;
        }

        $code = $packet['code'];
        $identifier = $packet['identifier'];
        $attributes = $packet['attributes'];
        $authenticator = $packet['authenticator'];

        switch ($code) {
            case RadiusServerAdapter::TYPE_ACCESS_REQUEST:
                $this->handleAccessRequest($identifier, $attributes, $from, $fromPort, $secret, $authenticator, $data);
                break;
            
            case RadiusServerAdapter::TYPE_ACCOUNTING_REQUEST:
                $this->handleAccountingRequest($identifier, $attributes, $from, $fromPort, $secret, $authenticator, $data);
                break;
        }
    }

    private function handleAccessRequest($id, $attrs, $from, $port, $secret, $authenticator, $rawData)
    {
        $username = $attrs[1] ?? null; // 1 = User-Name
        $password = $attrs[2] ?? null; // 2 = User-Password (already decrypted by decodePacket)

        if (!$username) {
            $this->sendReject($id, $from, $port, $secret, $authenticator, "Missing User-Name");
            $this->logEvent(null, $from, 'Login', 'Fail', "Access-Request without username");
            return;
        }

        DB::beginTransaction();
        try {
            $voucher = Voucher::where('code', $username)->with('plan')->lockForUpdate()->first();

            if (!$voucher) {
                DB::rollBack();
                
                // === RADIUS PROXY / CASCADE FEATURE ===
                $proxyStatus = $this->forwardToUpstreamRadius(RadiusServerAdapter::TYPE_ACCESS_REQUEST, $id, $attrs, $from, $port, $secret, $authenticator);
                
                if ($proxyStatus === 'success') {
                    $this->logEvent($username, $from, 'Proxy', 'Success', "Berhasil Proxy ke RADIUS Lain (" . env('UPSTREAM_RADIUS_IP') . ")");
                    return;
                } elseif ($proxyStatus === 'timeout') {
                    $this->logEvent($username, $from, 'Proxy', 'Fail', "Proxy Gagal: RADIUS Lain (" . env('UPSTREAM_RADIUS_IP') . ") Timeout/Mati");
                }
                // ======================================

                $this->sendReject($id, $from, $port, $secret, $authenticator, "Voucher not found");
                $this->logEvent($username, $from, 'Login', 'Fail', "Voucher code not found: $username");
                return;
            }

            // Password verification
            if ($password !== $voucher->code && $password !== '1') {
                // Heuristic: If password fails, maybe our secret was old? 
                if (time() - $this->lastClientRefresh > 2) {
                    $this->loadClients();
                    $secret = $this->clients[$from] ?? $secret;
                    $radius = new RadiusServerAdapter();
                    $newPassword = $radius->decryptPapPassword($attrs[2] ?? '', $secret, $authenticator);
                    if ($newPassword === $voucher->code || $newPassword === '1') {
                        $password = $newPassword;
                    }
                }
            }

            if ($password !== $voucher->code && $password !== '1') {
                DB::rollBack();
                $this->sendReject($id, $from, $port, $secret, $authenticator, "Wrong password");
                $this->logEvent($username, $from, 'Login', 'Fail', "Invalid password attempt for: $username");
                return;
            }

            if ($voucher->status === 'used') {
                if ($voucher->expires_at && Carbon::now()->gt($voucher->expires_at)) {
                    DB::rollBack();
                    $this->sendReject($id, $from, $port, $secret, $authenticator, "Voucher expired");
                    $this->logEvent($username, $from, 'Login', 'Fail', "Voucher expired: $username");
                    return;
                }

                // MAC Address Binding
                $incomingMac = $attrs[31] ?? null; // 31 = Calling-Station-Id
                if ($voucher->mac_address && $incomingMac && $voucher->mac_address !== $incomingMac) {
                    DB::rollBack();
                    $this->sendReject($id, $from, $port, $secret, $authenticator, "Voucher bound to another device");
                    $this->logEvent($username, $from, 'Login', 'Fail', "MAC Mismatch: Expected {$voucher->mac_address}, got $incomingMac");
                    return;
                }

                // Fix #4: Concurrent session check
                $maxSessions = $voucher->plan->shared_users ?? 1;
                $activeSessions = RadiusSession::activeSessionCount($username);
                if ($activeSessions >= $maxSessions) {
                    DB::rollBack();
                    $this->sendReject($id, $from, $port, $secret, $authenticator, "Max sessions reached ($activeSessions/$maxSessions)");
                    $this->logEvent($username, $from, 'Login', 'Fail', "Concurrent limit: $activeSessions active, max $maxSessions");
                    return;
                }
            } elseif ($voucher->status === 'available') {
                $durationStr = $voucher->plan->duration;
                $expiresAt = $this->calculateExpiry($durationStr);
                
                $voucher->update([
                    'status' => 'used',
                    'used_at' => now(),
                    'expires_at' => $expiresAt,
                    'mac_address' => $attrs[31] ?? null
                ]);
            } else {
                DB::rollBack();
                $this->sendReject($id, $from, $port, $secret, $authenticator, "Voucher not available");
                $this->logEvent($username, $from, 'Login', 'Fail', "Voucher status invalid: {$voucher->status}");
                return;
            }

            DB::commit();
            $this->logEvent($username, $from, 'Login', 'Success', "Authentication successful");
            $this->sendAccept($id, $from, $port, $secret, $authenticator, $voucher);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error: " . $e->getMessage());
            $this->sendReject($id, $from, $port, $secret, $authenticator, "System Error");
        }
    }

    private function sendAccept($id, $ip, $port, $secret, $requestAuthenticator, $voucher)
    {
        $radius = new RadiusServerAdapter();
        $responseAttrs = [
            27 => $this->getRemainingSeconds($voucher->expires_at),
            28 => 300,
        ];

        if ($voucher->plan) {
            $rateLimit = ($voucher->plan->upload_limit ?: '0') . 'k/' . ($voucher->plan->download_limit ?: '0') . 'k';
            $responseAttrs['Vendor-Specific'] = [149 => [8 => $rateLimit]];
        }

        $packet = $radius->encodePacket(RadiusServerAdapter::TYPE_ACCESS_ACCEPT, $id, $secret, $requestAuthenticator, $responseAttrs);
        socket_sendto($this->socket, $packet, strlen($packet), 0, $ip, $port);
    }

    private function sendReject($id, $ip, $port, $secret, $requestAuthenticator, $reason = "")
    {
        $radius = new RadiusServerAdapter();
        $packet = $radius->encodePacket(RadiusServerAdapter::TYPE_ACCESS_REJECT, $id, $secret, $requestAuthenticator, [
            18 => $reason
        ]);
        socket_sendto($this->socket, $packet, strlen($packet), 0, $ip, $port);
    }

    // Fix #3: Full Accounting handler with session tracking
    private function handleAccountingRequest($id, $attrs, $ip, $port, $secret, $requestAuthenticator, $rawData)
    {
        $username = $attrs[1] ?? 'Unknown';
        $statusType = $attrs[40] ?? 0;
        $sessionId = $attrs[44] ?? ($attrs[0x2C] ?? uniqid());
        
        $typeStr = 'Accounting';
        if ($statusType == 1) $typeStr = 'Start';
        if ($statusType == 2) $typeStr = 'Stop';
        if ($statusType == 3) $typeStr = 'Interim';

        // Check if this session or user belongs to our DB
        $isLocalUser = Voucher::where('code', $username)->exists() || RadiusSession::where('session_id', $sessionId)->exists();

        // If not a local user, forward to proxy
        if (!$isLocalUser) {
            $proxyStatus = $this->forwardToUpstreamRadius(RadiusServerAdapter::TYPE_ACCOUNTING_REQUEST, $id, $attrs, $ip, $port, $secret, $requestAuthenticator);
            if ($proxyStatus === 'success') {
                $this->logEvent($username, $ip, 'Proxy', 'Info', "Berhasil meneruskan data Accounting ke RADIUS Lain");
                return;
            } elseif ($proxyStatus === 'timeout') {
                $this->logEvent($username, $ip, 'Proxy', 'Fail', "Gagal Accounting: RADIUS Lain Timeout");
            }
        }

        try {
            switch ($statusType) {
                case 1: // Accounting-Start
                    RadiusSession::updateOrCreate(
                        ['session_id' => $sessionId, 'username' => $username],
                        [
                            'nas_ip' => $ip,
                            'nas_port' => $attrs[5] ?? null,
                            'mac_address' => $attrs[31] ?? null,
                            'framed_ip' => $attrs[8] ?? null,
                            'started_at' => now(),
                            'is_active' => true,
                            'bytes_in' => 0,
                            'bytes_out' => 0,
                            'session_time' => 0,
                        ]
                    );
                    $this->logEvent($username, $ip, 'Start', 'Success', "Session started: $sessionId");
                    break;

                case 2: // Accounting-Stop
                    $session = RadiusSession::where('session_id', $sessionId)->first();
                    if ($session) {
                        $session->update([
                            'stopped_at' => now(),
                            'is_active' => false,
                            'bytes_in' => $attrs[42] ?? $session->bytes_in,   // Acct-Input-Octets
                            'bytes_out' => $attrs[43] ?? $session->bytes_out, // Acct-Output-Octets
                            'session_time' => $attrs[46] ?? $session->session_time, // Acct-Session-Time
                            'terminate_cause' => $this->getTerminateCause($attrs[49] ?? 0),
                        ]);
                    } else {
                        // Session not found (maybe server restarted), create a closed record
                        RadiusSession::create([
                            'session_id' => $sessionId,
                            'username' => $username,
                            'nas_ip' => $ip,
                            'mac_address' => $attrs[31] ?? null,
                            'framed_ip' => $attrs[8] ?? null,
                            'stopped_at' => now(),
                            'is_active' => false,
                            'bytes_in' => $attrs[42] ?? 0,
                            'bytes_out' => $attrs[43] ?? 0,
                            'session_time' => $attrs[46] ?? 0,
                            'terminate_cause' => $this->getTerminateCause($attrs[49] ?? 0),
                        ]);
                    }
                    $this->logEvent($username, $ip, 'Stop', 'Success', "Session stopped: $sessionId");
                    break;

                case 3: // Interim-Update
                    RadiusSession::where('session_id', $sessionId)->update([
                        'bytes_in' => $attrs[42] ?? 0,
                        'bytes_out' => $attrs[43] ?? 0,
                        'session_time' => $attrs[46] ?? 0,
                    ]);
                    $this->logEvent($username, $ip, 'Interim', 'Info', "Session update: $sessionId");
                    break;

                default:
                    $this->logEvent($username, $ip, $typeStr, 'Info', "Packet received");
                    break;
            }
        } catch (\Exception $e) {
            $this->error("Accounting Error: " . $e->getMessage());
            $this->logEvent($username, $ip, $typeStr, 'Fail', "Error: " . $e->getMessage());
        }

        // Always ACK accounting requests
        $radius = new RadiusServerAdapter();
        $packet = $radius->encodePacket(RadiusServerAdapter::TYPE_ACCOUNTING_RESPONSE, $id, $secret, $requestAuthenticator, []);
        socket_sendto($this->socket, $packet, strlen($packet), 0, $ip, $port);
    }

    private function getTerminateCause($code)
    {
        $causes = [
            1 => 'User-Request',
            2 => 'Lost-Carrier',
            3 => 'Lost-Service',
            4 => 'Idle-Timeout',
            5 => 'Session-Timeout',
            6 => 'Admin-Reset',
            7 => 'Admin-Reboot',
            8 => 'Port-Error',
            9 => 'NAS-Error',
            10 => 'NAS-Request',
            11 => 'NAS-Reboot',
            12 => 'Port-Unneeded',
            13 => 'Port-Preempted',
            14 => 'Port-Suspended',
            15 => 'Service-Unavailable',
            16 => 'Callback',
            17 => 'User-Error',
            18 => 'Host-Request',
        ];
        return $causes[$code] ?? "Unknown ($code)";
    }

    private function logEvent($username, $ip, $type, $status, $message)
    {
        RadiusLog::create([
            'username' => $username,
            'client_ip' => $ip,
            'type' => $type,
            'status' => $status,
            'message' => $message
        ]);
        
        $color = $status === 'Success' ? 'info' : ($status === 'Fail' ? 'error' : 'comment');
        $this->{$color}("[$type] $status: $username ($ip) - $message");
    }

    private function calculateExpiry($durationStr)
    {
        if (!$durationStr) return null;
        $now = now();
        if (preg_match('/(\d+)d/', $durationStr, $m)) $now->addDays((int)$m[1]);
        if (preg_match('/(\d+)h/', $durationStr, $m)) $now->addHours((int)$m[1]);
        if (preg_match('/(\d+)m/', $durationStr, $m)) $now->addMonths((int)$m[1]);
        return $now;
    }

    private function getRemainingSeconds($expiresAt)
    {
        if (!$expiresAt) return 0;
        $diff = Carbon::now()->diffInSeconds($expiresAt, false);
        return $diff > 0 ? $diff : 0;
    }

    private function forwardToUpstreamRadius($code, $id, $attrs, $from, $port, $mikrotikSecret, $requestAuth)
    {
        $upstreamIp = env('UPSTREAM_RADIUS_IP');
        $upstreamPort = env('UPSTREAM_RADIUS_PORT', 1812);
        $upstreamSecret = env('UPSTREAM_RADIUS_SECRET');

        if (!$upstreamIp || !$upstreamSecret) {
            return false; // Proxy disabled or missing secret
        }

        $radius = new RadiusServerAdapter();
        
        // 1. Prepare attributes for Upstream
        $upstreamAttrs = $attrs;
        $upstreamRequestAuth = $requestAuth;
        
        if ($code == RadiusServerAdapter::TYPE_ACCESS_REQUEST) {
            $upstreamRequestAuth = random_bytes(16);
            if (isset($attrs[2])) {
                // $attrs[2] is the plain text password decrypted earlier. We re-encrypt it for upstream.
                $upstreamAttrs[2] = $radius->encryptPapPassword($attrs[2], $upstreamSecret, $upstreamRequestAuth);
            }
        }

        // 2. Encode packet for Upstream
        $upstreamPacket = $radius->encodePacket($code, $id, $upstreamSecret, $upstreamRequestAuth, $upstreamAttrs);

        // 3. Send to Upstream
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) return false;
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
        socket_sendto($sock, $upstreamPacket, strlen($upstreamPacket), 0, $upstreamIp, $upstreamPort);
        
        $reply = '';
        $replyFrom = '';
        $replyPort = 0;
        
        // 4. Wait for Upstream Response
        if (@socket_recvfrom($sock, $reply, 4096, 0, $replyFrom, $replyPort)) {
            socket_close($sock);
            
            // 5. Decode Upstream's Response
            $decodedReply = $radius->decodePacket($reply, $upstreamSecret);
            if (!$decodedReply) return false;

            // 6. Re-encode Response for MikroTik using original RequestAuth and MikroTik Secret
            $mikrotikPacket = $radius->encodePacket(
                $decodedReply['code'], 
                $id, 
                $mikrotikSecret, 
                $requestAuth, 
                $decodedReply['attributes']
            );

            // 7. Send back to MikroTik
            socket_sendto($this->socket, $mikrotikPacket, strlen($mikrotikPacket), 0, $from, $port);
            return true;
        }
        
        socket_close($sock);
        return false;
    }
}
