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
        $this->info("Starting profile synchronization...");

        $vouchers = Voucher::with('plan')->where('status', 'used')->get();

        foreach ($vouchers as $v) {
            if (!$v->plan) continue;

            $profileName = $v->plan->mikrotik_profile ?: $v->plan->name;
            
            // Re-inject/Update user in Mikrotik
            // This will fix "unknown" profiles
            $result = $this->mikrotik->createUser([
                'username' => $v->code,
                'password' => '',
                'profile' => $profileName,
                'comment' => 'Synced from ND-Hotspot v2',
                'limit_uptime' => $v->plan->duration ?: '0'
            ]);

            if (isset($result['!trap'])) {
                // If user already exists, update their profile
                $this->info("User {$v->code} exists, updating profile to {$profileName}...");
                $this->mikrotik->updateUserProfile($v->code, $profileName);
            } else {
                $this->info("User {$v->code} re-created with profile {$profileName}");
            }
        }

        $this->info("Synchronization finished!");
    }
}
