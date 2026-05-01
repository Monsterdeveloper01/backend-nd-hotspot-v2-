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

        // 1. Sync Active Users from Mikrotik to mark vouchers as 'used' and set 'expires_at'
        $activeUsers = $this->mikrotik->getActiveUsers();
        foreach ($activeUsers as $user) {
            $username = $user['user'] ?? null;
            if (!$username) continue;

            $voucher = Voucher::with('plan')->where('code', $username)->where('status', '!=', 'used')->first();
            
            if ($voucher && $voucher->plan) {
                $durationStr = $voucher->plan->duration;
                $now = now();
                $expiresAt = clone $now;

                if (preg_match('/(\d+)d/', $durationStr, $m)) $expiresAt->addDays((int)$m[1]);
                if (preg_match('/(\d+)h/', $durationStr, $m)) $expiresAt->addHours((int)$m[1]);
                if (preg_match('/(\d+)m/', $durationStr, $m)) $expiresAt->addMonths((int)$m[1]);
                
                $voucher->update([
                    'status' => 'used',
                    'used_at' => $now,
                    'expires_at' => $expiresAt,
                    'mac_address' => $user['mac-address'] ?? null
                ]);
                
                $this->info("Marked voucher {$voucher->code} as used. Expires at: $expiresAt");
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
            
            // We keep it as 'used' in DB for history, but since expires_at < now,
            // the RADIUS server will reject any future login attempts.
            $this->info("Voucher {$v->code} has been cleared from Mikrotik.");
        }

        $this->info("Housekeeping finished.");
    }
}
