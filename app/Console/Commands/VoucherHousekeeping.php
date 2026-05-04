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
        $this->info("Starting Smart Voucher Housekeeping...");

        // 1. Ambil SEMUA user dari MikroTik sebagai acuan utama
        $allMikrotikUsers = $this->mikrotik->getAllHotspotUsers();
        
        if (empty($allMikrotikUsers)) {
            $this->info("Tidak ada user di MikroTik. Selesai.");
            return;
        }

        $now = now();

        foreach ($allMikrotikUsers as $mUser) {
            $code = $mUser['name'] ?? null;
            $uptime = $mUser['uptime'] ?? '0s';

            if (!$code || $code === 'admin') continue;

            // Cari data vouchernya di database
            $voucher = Voucher::with('plan')->where('code', $code)->first();

            if (!$voucher) {
                // Opsional: Jika user ada di MikroTik tapi tidak ada di DB, 
                // ini mungkin user manual atau sisa-sisa lama. Kita biarkan saja atau hapus jika perlu.
                continue;
            }

            // LOGIKA A: Deteksi Penggunaan Pertama (Set expires_at)
            if ($voucher->status === 'sold' && $uptime !== '0s') {
                if (!$voucher->used_at && $voucher->plan) {
                    $durationStr = $voucher->plan->duration;
                    $expiresAt = clone $now;
                    
                    if (preg_match('/(\d+)d/', $durationStr, $m)) $expiresAt->addDays((int)$m[1]);
                    if (preg_match('/(\d+)h/', $durationStr, $m)) $expiresAt->addHours((int)$m[1]);
                    if (preg_match('/(\d+)m/', $durationStr, $m)) $expiresAt->addMinutes((int)$m[1]);

                    $voucher->update([
                        'status' => 'used',
                        'used_at' => $now,
                        'expires_at' => $expiresAt,
                        'mac_address' => $mUser['mac-address'] ?? null
                    ]);
                    $this->info("Voucher {$code} terdeteksi mulai digunakan. Exp: {$expiresAt}");
                }
            }

            // LOGIKA B: Hapus Jika Sudah Kedaluwarsa
            $isExpired = ($voucher->expires_at && $voucher->expires_at < $now);
            $shouldBeDeleted = ($isExpired || in_array($voucher->status, ['expired', 'archive']));

            if ($shouldBeDeleted) {
                $this->info("Menghapus voucher kedaluwarsa dari MikroTik: {$code}");
                
                try {
                    $this->mikrotik->removeHotspotUser($code);
                    $this->mikrotik->clearUserActiveSessions($code);
                    $this->mikrotik->clearUserCookies($code);

                    // Update status di DB jika belum 'expired'
                    if ($voucher->status !== 'expired') {
                        $voucher->update(['status' => 'expired']);
                    }
                    $this->info("Voucher {$code} berhasil dibersihkan.");
                } catch (\Exception $e) {
                    $this->error("Gagal membersihkan {$code}: " . $e->getMessage());
                }
            }
        }

        $this->info("Housekeeping selesai.");
    }
}
