<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Models\RadiusClient;
use App\Models\RadiusLog;
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
        // 5. loadClients() Refresh Logic (Cooldown/Cache)
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
                $this->handleAccessRequest($identifier, $attributes, $from, $fromPort, $secret, $authenticator);
                break;
            
            case RadiusServerAdapter::TYPE_ACCOUNTING_REQUEST:
                $this->handleAccountingRequest($identifier, $attributes, $from, $fromPort, $secret, $authenticator);
                break;
        }
    }

    private function handleAccessRequest($id, $attrs, $from, $port, $secret, $authenticator)
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

    private function handleAccountingRequest($id, $attrs, $ip, $port, $secret, $requestAuthenticator)
    {
        $username = $attrs[1] ?? 'Unknown';
        $statusType = $attrs[40] ?? 0;
        
        $typeStr = 'Accounting';
        if ($statusType == 1) $typeStr = 'Start';
        if ($statusType == 2) $typeStr = 'Stop';
        if ($statusType == 3) $typeStr = 'Interim';

        $this->logEvent($username, $ip, $typeStr, 'Info', "Packet received");

        $radius = new RadiusServerAdapter();
        $packet = $radius->encodePacket(RadiusServerAdapter::TYPE_ACCOUNTING_RESPONSE, $id, $secret, $requestAuthenticator, []);
        socket_sendto($this->socket, $packet, strlen($packet), 0, $ip, $port);
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
        if (preg_match('/(\d+)m/', $durationStr, $m)) $now->addMinutes((int)$m[1]);
        return $now;
    }

    private function getRemainingSeconds($expiresAt)
    {
        if (!$expiresAt) return 0;
        $diff = Carbon::now()->diffInSeconds($expiresAt, false);
        return $diff > 0 ? $diff : 0;
    }
}
