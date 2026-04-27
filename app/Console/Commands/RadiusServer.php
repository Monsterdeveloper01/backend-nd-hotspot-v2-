<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Models\RadiusClient;
use App\Models\RadiusLog;
use App\Services\RadiusServerAdapter;
use Carbon\Carbon;

class RadiusServer extends Command
{
    protected $signature = 'radius:serve {--port=1812}';
    protected $description = 'Start the ND-Hotspot RADIUS Server';

    private $socket;
    private $clients = [];

    public function handle()
    {
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

        while (true) {
            $from = '';
            $fromPort = 0;
            $data = '';
            
            socket_recvfrom($this->socket, $data, 1024, 0, $from, $fromPort);

            if ($data) {
                $this->handlePacket($data, $from, $fromPort);
            }
        }
    }

    private function loadClients()
    {
        $this->clients = RadiusClient::all()->pluck('shared_secret', 'ip_address')->toArray();
    }

    private function handlePacket($data, $from, $fromPort)
    {
        // Jika IP tidak ada di cache, coba refresh data dari database
        if (!isset($this->clients[$from])) {
            $this->loadClients();
        }

        if (!isset($this->clients[$from])) {
            $this->warn("Received packet from unknown client: $from");
            $this->logEvent(null, $from, 'Auth', 'Fail', "Unknown client attempted connection");
            return;
        }

        $secret = $this->clients[$from];
        $radius = new RadiusServerAdapter();
        
        // Extract authenticator first for PAP password decryption
        $authenticator = substr($data, 4, 16);

        $packet = $radius->decodePacket($data, $secret);
        
        if (!$packet) {
            $this->error("Failed to decode packet from $from");
            $this->logEvent(null, $from, 'Auth', 'Fail', "Invalid packet or secret mismatch");
            return;
        }

        $code = $packet['code'];
        $identifier = $packet['identifier'];
        $attributes = $packet['attributes'];

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

        if (!$username) {
            $this->sendReject($id, $from, $port, $secret, $authenticator, "Missing User-Name");
            $this->logEvent(null, $from, 'Login', 'Fail', "Access-Request without username");
            return;
        }

        $voucher = Voucher::where('code', $username)->with('plan')->first();

        if (!$voucher) {
            $this->sendReject($id, $from, $port, $secret, $authenticator, "Voucher not found");
            $this->logEvent($username, $from, 'Login', 'Fail', "Voucher code not found in database");
            return;
        }

        if ($voucher->status === 'used' && $voucher->expires_at && Carbon::now()->gt($voucher->expires_at)) {
            $this->sendReject($id, $from, $port, $secret, $authenticator, "Voucher expired");
            $this->logEvent($username, $from, 'Login', 'Fail', "Voucher has already expired");
            return;
        }

        if ($voucher->status === 'available') {
            $durationStr = $voucher->plan->duration;
            $expiresAt = $this->calculateExpiry($durationStr);
            
            $voucher->update([
                'status' => 'used',
                'used_at' => now(),
                'expires_at' => $expiresAt,
                'mac_address' => $attrs[31] ?? null // 31 = Calling-Station-Id
            ]);
            $this->logEvent($username, $from, 'Login', 'Success', "First login detected. Expiry set to $expiresAt");
        } else {
            $this->logEvent($username, $from, 'Login', 'Success', "Subsequent login accepted");
        }

        $this->sendAccept($id, $from, $port, $secret, $authenticator, $voucher);
    }

    private function sendAccept($id, $ip, $port, $secret, $requestAuthenticator, $voucher)
    {
        $radius = new RadiusServerAdapter();
        $responseAttrs = [
            27 => $this->getRemainingSeconds($voucher->expires_at), // 27 = Session-Timeout
            28 => 300, // 28 = Idle-Timeout
        ];

        if ($voucher->plan) {
            $rateLimit = ($voucher->plan->upload_limit ?: '0') . '/' . ($voucher->plan->download_limit ?: '0');
            // MikroTik Vendor-Specific Attribute for Rate-Limit
            $responseAttrs['Vendor-Specific'] = [149 => [8 => $rateLimit]];
        }

        $packet = $radius->encodePacket(RadiusServerAdapter::TYPE_ACCESS_ACCEPT, $id, $secret, $requestAuthenticator, $responseAttrs);
        socket_sendto($this->socket, $packet, strlen($packet), 0, $ip, $port);
    }

    private function sendReject($id, $ip, $port, $secret, $requestAuthenticator, $reason = "")
    {
        $radius = new RadiusServerAdapter();
        $packet = $radius->encodePacket(RadiusServerAdapter::TYPE_ACCESS_REJECT, $id, $secret, $requestAuthenticator, []);
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

        $this->logEvent($username, $ip, $typeStr, 'Info', "Accounting packet received");

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
