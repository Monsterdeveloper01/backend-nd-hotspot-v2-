<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Services\MikrotikService;

class SyncMikrotikProfiles extends Command
{
    protected $signature = 'voucher:sync-profiles';
    protected $description = 'Sync legacy vouchers with their correct Mikrotik profiles';

    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
    }

    public function handle()
    {
        $this->info("==================================================");
        $this->info("  ND-Hotspot: MikroTik Profile & Voucher Sync");
        $this->info("==================================================");

        // Langkah 1: Sinkronkan Master Voucher Plans ke Hotspot User Profiles di MikroTik
        $this->info("\n[1/2] Menyinkronkan Profil Paket (Master Voucher) ke MikroTik...");
        $profileSync = $this->mikrotik->syncAllProfiles();

        if ($profileSync['success']) {
            $this->info("✓ " . $profileSync['message']);
        } else {
            $this->warn("! " . $profileSync['message']);
            if (!empty($profileSync['errors'])) {
                foreach ($profileSync['errors'] as $err) {
                    $this->error("  - " . $err);
                }
            }
        }

        // Langkah 2: Sinkronkan user vouchers
        $this->info("\n[2/2] Memeriksa voucher terpakai & terjual di database...");
        $vouchers = Voucher::with('plan')->whereIn('status', ['used', 'sold'])->get();

        $syncedCount = 0;
        foreach ($vouchers as $v) {
            if (!$v->plan) continue;

            $profileName = $v->plan->mikrotik_profile ?: $v->plan->name;
            
            // Re-inject/Update user in Mikrotik
            $result = $this->mikrotik->createUser([
                'username' => $v->code,
                'password' => '',
                'profile' => $profileName,
                'comment' => 'Synced from ND-Hotspot v2',
                'limit_uptime' => $v->plan->duration ?: '0'
            ]);

            if (is_array($result) && (isset($result['!trap']) || (isset($result['type']) && $result['type'] === '!trap'))) {
                $trap = $result['message'] ?? $result['!trap'] ?? '';
                if (str_contains(strtolower($trap), 'already exists') || str_contains(strtolower($trap), 'failure')) {
                    $this->mikrotik->updateUserProfile($v->code, $profileName);
                    $syncedCount++;
                } else {
                    $this->warn("User {$v->code} gagal: {$trap}");
                }
            } else {
                $this->info("User {$v->code} disinkronkan ke profil {$profileName}");
                $syncedCount++;
            }
        }

        $this->info("\n✓ Selesai! {$profileSync['synced']} profil dan {$syncedCount} user berhasil disinkronkan dengan MikroTik.");
    }
}
