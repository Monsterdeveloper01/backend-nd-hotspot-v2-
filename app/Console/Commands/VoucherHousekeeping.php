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

        // Use batch mode: ONE connection for the entire housekeeping run
        if (!$this->mikrotik->beginBatch()) {
            $this->error("Gagal koneksi ke MikroTik. Housekeeping dibatalkan.");
            return;
        }

        try {
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

                        if ($voucher->source === 'reward') {
                            \App\Models\EventReward::where('voucher_id', $voucher->id)
                                ->where('status', 'issued')
                                ->update(['status' => 'used']);
                        }

                        $this->info("Voucher {$code} terdeteksi mulai digunakan. Exp: {$expiresAt}");
                    }
                }

                // LOGIKA B: Hapus Dari MikroTik Jika Sudah Kedaluwarsa
                // Perhatian: Voucher QRIS biasa yang belum terpakai (status sold) TIDAK memiliki batas kedaluwarsa 3 hari.
                // Batas 3 hari HANYA berlaku untuk voucher yang dibeli menggunakan ND-Points (atau reward).
                $isPointOrReward = $voucher->isPointVoucher() || $voucher->source === 'reward';
                $isExpired = false;

                if ($voucher->status === 'sold') {
                    // Jika belum dipakai, HANYA kedaluwarsa jika berasal dari poin/reward dan expires_at sudah lewat
                    $isExpired = $isPointOrReward && $voucher->expires_at && $voucher->expires_at < $now;
                } elseif ($voucher->status === 'used') {
                    // Jika sudah dipakai, kedaluwarsa saat limit waktu paketnya habis
                    $isExpired = ($voucher->expires_at && $voucher->expires_at < $now);
                }

                $shouldBeDeleted = ($isExpired || in_array($voucher->status, ['expired', 'archive']));

                if ($shouldBeDeleted) {
                    $this->info("Menghapus user voucher kedaluwarsa dari MikroTik: {$code}");
                    
                    try {
                        // HAPUS HANYA DARI MIKROTIK
                        $this->mikrotik->removeAndCleanUser($code);

                        // PENTING: JANGAN PERNAH HAPUS DARI DATABASE!
                        // Cukup ubah status di DB menjadi 'expired' untuk rekam jejak
                        if ($voucher->status !== 'expired') {
                            $voucher->update(['status' => 'expired']);
                            if ($voucher->source === 'reward') {
                                \App\Models\EventReward::where('voucher_id', $voucher->id)
                                    ->whereIn('status', ['issued', 'processing'])
                                    ->update(['status' => 'expired']);
                            }
                        }
                        $this->info("User MikroTik {$code} dibersihkan, status DB diset 'expired'.");
                    } catch (\Exception $e) {
                        $this->error("Gagal membersihkan user MikroTik {$code}: " . $e->getMessage());
                    }
                }
            }

            // 2. Bersihkan voucher poin kedaluwarsa di database yang belum terhapus dari MikroTik
            // HANYA untuk voucher poin (atau reward) batas 3 hari, atau voucher used yang sudah habis masa aktifnya.
            // Voucher QRIS biasa yang belum terpakai (sold) TIDAK PERNAH dikadaluarsakan di sini!
            $dbExpired = Voucher::with(['transaction'])
                ->whereIn('status', ['sold', 'used'])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->get();

            if ($dbExpired->isNotEmpty()) {
                $this->info("Menemukan {$dbExpired->count()} voucher calon kedaluwarsa di database. Memeriksa...");
                foreach ($dbExpired as $expV) {
                    // Voucher QRIS biasa yang belum terpakai TIDAK BOLEH dikadaluarsakan!
                    if ($expV->status === 'sold' && !$expV->isPointVoucher() && $expV->source !== 'reward') {
                        continue;
                    }

                    try {
                        // Hapus user di MikroTik saja
                        $this->mikrotik->removeAndCleanUser($expV->code);
                    } catch (\Throwable $e) {}

                    // PENTING: JANGAN DIHAPUS DI DATABASE!
                    // Cukup update status menjadi 'expired'
                    $expV->update(['status' => 'expired']);
                    if ($expV->source === 'reward') {
                        \App\Models\EventReward::where('voucher_id', $expV->id)
                            ->whereIn('status', ['issued', 'processing'])
                            ->update(['status' => 'expired']);
                    }
                    $this->info("User MikroTik {$expV->code} dihapus & status DB diupdate ke 'expired'. Record database tetap tersimpan.");
                }
            }

            // 3. AUTO-HEAL: Pastikan semua voucher berstatus 'sold' yang aktif di database sudah ter-inject ke MikroTik!
            $soldVouchers = Voucher::where('status', 'sold')->with('plan')->get();
            $mikrotikUsernames = collect($allMikrotikUsers)->pluck('name')->flip();
            $healedCount = 0;

            foreach ($soldVouchers as $sv) {
                if (!$mikrotikUsernames->has($sv->code)) {
                    $profile = $sv->plan?->mikrotik_profile ?: $sv->plan?->name ?: 'default';
                    try {
                        $res = $this->mikrotik->createUser([
                            'username' => $sv->code,
                            'password' => '',
                            'profile' => $profile,
                            'limit_uptime' => $sv->plan?->duration ?: '0',
                            'comment' => "ND-AUTOHEAL: {$sv->code} ({$sv->customer_phone})"
                        ]);
                        $isResOk = is_array($res) && !isset($res['!trap']) && !isset($res['type']) && (isset($res[0]['ret']) || isset($res[0]['.id']) || !empty($res));
                        if ($isResOk) {
                            $resId = $res[0]['ret'] ?? $res[0]['.id'] ?? 'created';
                            $sv->update(['mikrotik_id' => $resId]);
                            $healedCount++;
                            $this->info("Berhasil meng-inject kembali voucher missing ke MikroTik: {$sv->code}");
                        }
                    } catch (\Throwable $he) {
                        $this->error("Gagal auto-heal voucher {$sv->code}: " . $he->getMessage());
                    }
                }
            }
            if ($healedCount > 0) {
                $this->info("Auto-heal selesai. {$healedCount} voucher yang sempat hilang telah berhasil di-inject ke MikroTik.");
            }

            $this->info("Housekeeping selesai.");
        } finally {
            // ALWAYS close the connection, even if an exception occurred
            $this->mikrotik->endBatch();
        }
    }
}
