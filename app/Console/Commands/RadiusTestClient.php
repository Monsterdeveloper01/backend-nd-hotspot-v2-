<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RadiusServerAdapter;
use App\Models\RadiusClient;

class RadiusTestClient extends Command
{
    protected $signature = 'radius:test {username} {password?} {--ip=127.0.0.1} {--port=1812} {--secret=}';
    protected $description = 'Simulate a MikroTik NAS to test the RADIUS server';

    public function handle()
    {
        $username = $this->argument('username');
        $password = $this->argument('password') ?? $username;
        $ip = $this->option('ip');
        $port = $this->option('port');
        
        $secret = $this->option('secret');
        
        if (!$secret) {
            // Auto-detect secret from DB if testing locally
            $client = RadiusClient::where('ip_address', '127.0.0.1')->first() ?? RadiusClient::first();
            if (!$client) {
                $this->error("No RADIUS Client configured in DB. Please provide --secret=xxx");
                return;
            }
            $secret = $client->shared_secret;
            $this->info("Using auto-detected Secret: $secret");
        }

        $this->info("📡 Simulating MikroTik NAS...");
        $this->info("Sending login request for user: '$username' to $ip:$port");

        $radius = new RadiusServerAdapter();
        $requestAuth = random_bytes(16);
        $encryptedPassword = $radius->encryptPapPassword($password, $secret, $requestAuth);

        $attrs = [
            1 => $username,
            2 => $encryptedPassword,
            4 => '127.0.0.1', // NAS-IP-Address
            31 => 'AA:BB:CC:DD:EE:FF', // Calling-Station-Id (Fake MAC)
            32 => 'Test-MikroTik' // NAS-Identifier
        ];

        $identifier = rand(0, 255);
        $packet = $radius->encodePacket(RadiusServerAdapter::TYPE_ACCESS_REQUEST, $identifier, $secret, $requestAuth, $attrs);

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);

        socket_sendto($sock, $packet, strlen($packet), 0, $ip, $port);
        
        $this->line("Waiting for response...");

        $reply = '';
        $from = '';
        $fromPort = 0;

        if (@socket_recvfrom($sock, $reply, 4096, 0, $from, $fromPort)) {
            $decoded = $radius->decodePacket($reply, $secret);
            
            if (!$decoded) {
                $this->error("❌ Received invalid/corrupted packet. Secret mismatch?");
                return;
            }

            if ($decoded['code'] == RadiusServerAdapter::TYPE_ACCESS_ACCEPT) {
                $this->info("\n✅ [ACCESS-ACCEPT] Login Berhasil!");
                $this->line("Data yang dikirim server ke MikroTik:");
                foreach ($decoded['attributes'] as $k => $v) {
                    if ($k === 'Vendor-Specific') {
                        $this->line("  ▶ Limit Kuota/Speed: " . json_encode($v));
                    } elseif ($k === 27) {
                        $this->line("  ▶ Sisa Waktu (Session-Timeout): " . $v . " detik");
                    } else {
                        $this->line("  ▶ Attr $k: $v");
                    }
                }
            } elseif ($decoded['code'] == RadiusServerAdapter::TYPE_ACCESS_REJECT) {
                $this->error("\n❌ [ACCESS-REJECT] Login Ditolak!");
                if (isset($decoded['attributes'][18])) {
                    $this->line("Alasan (Reply-Message): " . $decoded['attributes'][18]);
                }
            } else {
                $this->warn("\n⚠️ Received Unknown Packet Code: " . $decoded['code']);
            }
        } else {
            $this->error("\n⏳ TIMEOUT! Server RADIUS tidak merespons. Pastikan 'php artisan radius:serve' sedang berjalan.");
        }

        socket_close($sock);
    }
}
