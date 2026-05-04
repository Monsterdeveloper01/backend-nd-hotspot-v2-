<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Services\MikrotikService;
use Carbon\Carbon;

class VoucherHousekeeping extends Command
{
    protected $signature = 'voucher:cleanup';
    protected $description = 'Clean up expired vouchers and sync usage status with Mikrotik';

    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
    }

    public function handle()
    {
        $this->info("Starting Voucher Housekeeping...");

        // 1. Sync ALL Hotspot Users from Mikrotik to find those who have started using their vouchers
        $allUsers = $this->mikrotik->getAllHotspotUsers();
        foreach ($allUsers as $user) {
            $username = $user['name'] ?? null;
            $uptime = $user['uptime'] ?? '0s';
            
            if (!$username || $uptime === '0s') continue;

            // Find voucher that is not marked as used yet
            $voucher = Voucher::with('plan')->where('code', $username)->where('status', 'sold')->first();
            
            if ($voucher && $voucher->plan) {
                $durationStr = $voucher->plan->duration;
                $now = now();
                
                // If we don't have used_at yet, set it (this means it's the first time we detect it's been used)
                if (!$voucher->used_at) {
                    $expiresAt = clone $now;
                    if (preg_match('/(\d+)d/', $durationStr, $m)) $expiresAt->addDays((int)$m[1]);
                    if (preg_match('/(\d+)h/', $durationStr, $m)) $expiresAt->addHours((int)$m[1]);
                    if (preg_match('/(\d+)m/', $durationStr, $m)) $expiresAt->addMinutes((int)$m[1]);
                    
                    $voucher->update([
                        'status' => 'used',
                        'used_at' => $now,
                        'expires_at' => $expiresAt,
                        'mac_address' => $user['mac-address'] ?? null
                    ]);
                    $this->info("Marked voucher {$voucher->code} as used. Expires at: $expiresAt");
                }
            }
        }

        // 2. Cleanup expired vouchers from Mikrotik and mark status
        $expired = Voucher::where('status', 'used')
            ->where('expires_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $v) {
            $this->info("Cleaning up expired voucher: {$v->code}");
            
            // Remove from Mikrotik
            $this->mikrotik->removeHotspotUser($v->code);
            $this->mikrotik->clearUserActiveSessions($v->code);
            $this->mikrotik->clearUserCookies($v->code);
            
            // Update status to expired in DB so we don't process it again next time
            $v->update(['status' => 'expired']);
            $this->info("Voucher {$v->code} has been cleared from Mikrotik and marked as expired.");
        }

        $this->info("Housekeeping finished.");
    }
}
