<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Services\MikrotikService;
use Carbon\Carbon;

class VoucherHousekeeping extends Command
{
    protected $signature = 'voucher:cleanup {--days=60 : Batas hari kedaluwarsa voucher QRIS yang belum dipakai (default 60 hari / 2 bulan)}';
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

        $abandonedDays = (int) ($this->option('days') ?: 60);
        $abandonedCutoff = now()->subDays($abandonedDays);
        $this->info("Batas kedaluwarsa voucher QRIS hangus: {$abandonedDays} hari (dibeli sebelum {$abandonedCutoff->toDateTimeString()}).");

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
                // 1. Voucher poin/reward: kedaluwarsa 3 hari setelah klaim (sesuai expires_at)
                // 2. Voucher used: kedaluwarsa setelah limit masa aktif paket habis
                // 3. Voucher QRIS biasa: jika dibeli >= 2 bulan lalu (default 60 hari) dan belum pernah dipakai sama sekali
                $isPointOrReward = $voucher->isPointVoucher() || $voucher->source === 'reward';
                $isExpired = false;

                if ($voucher->status === 'sold') {
                    if ($isPointOrReward) {
                        $isExpired = $voucher->expires_at && $voucher->expires_at < $now;
                    } else {
                        // Voucher QRIS biasa yang berumur >= 2 bulan tanpa pernah digunakan
                        $isExpired = ($voucher->created_at && $voucher->created_at <= $abandonedCutoff && $uptime === '0s');
                    }
                } elseif ($voucher->status === 'used') {
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
                            $voucher->update([
                                'status' => 'expired',
                                'expires_at' => $voucher->expires_at ?: $now
                            ]);
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

            // 2. Bersihkan voucher kedaluwarsa di database yang belum terhapus dari MikroTik
            // a) Voucher poin/reward yang sudah lewat expires_at (3 hari)
            // b) Voucher yang used dan sudah habis limit waktunya
            // c) Voucher QRIS biasa yang sudah dibeli >= 2 bulan (60 hari) tanpa pernah dipakai
            $dbExpired = Voucher::with(['transaction'])
                ->whereIn('status', ['sold', 'used'])
                ->where(function ($q) use ($now, $abandonedCutoff) {
                    $q->where(function ($sq) use ($now) {
                        $sq->whereNotNull('expires_at')
                           ->where('expires_at', '<', $now);
                    })
                    ->orWhere(function ($sq) use ($abandonedCutoff) {
                        $sq->where('status', 'sold')
                           ->whereNull('used_at')
                           ->where('created_at', '<=', $abandonedCutoff);
                    });
                })
                ->get();

            if ($dbExpired->isNotEmpty()) {
                $this->info("Menemukan {$dbExpired->count()} voucher calon kedaluwarsa di database. Memeriksa...");
                foreach ($dbExpired as $expV) {
                    $isPointOrReward = $expV->isPointVoucher() || $expV->source === 'reward';
                    $isQrisAbandoned = ($expV->status === 'sold' && !$isPointOrReward && $expV->created_at <= $abandonedCutoff && !$expV->used_at);

                    // Voucher QRIS biasa yang belum terpakai HANYA dikadaluarsakan jika berumur >= 2 bulan!
                    if ($expV->status === 'sold' && !$isPointOrReward && !$isQrisAbandoned) {
                        continue;
                    }

                    try {
                        // Hapus user di MikroTik saja
                        $this->mikrotik->removeAndCleanUser($expV->code);
                    } catch (\Throwable $e) {}

                    // PENTING: JANGAN DIHAPUS DI DATABASE!
                    // Cukup update status menjadi 'expired'
                    $expV->update([
                        'status' => 'expired',
                        'expires_at' => $expV->expires_at ?: $now
                    ]);
                    if ($expV->source === 'reward') {
                        \App\Models\EventReward::where('voucher_id', $expV->id)
                            ->whereIn('status', ['issued', 'processing'])
                            ->update(['status' => 'expired']);
                    }
                    $reason = $isQrisAbandoned ? "Voucher QRIS tidak dipakai > {$abandonedDays} hari" : "Masa aktif habis";
                    $this->info("User MikroTik {$expV->code} dihapus ({$reason}) & status DB diupdate ke 'expired'.");
                }
            }

            // 3. AUTO-HEAL: Pastikan semua voucher berstatus 'sold' yang masih VALID (< 60 hari) ter-inject ke MikroTik!
            $soldVouchers = Voucher::where('status', 'sold')
                ->where('created_at', '>', $abandonedCutoff)
                ->with('plan')
                ->get();
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
                        $resMsg = strtolower($res['message'] ?? $res['!trap'] ?? '');
                        if ($isResOk || str_contains($resMsg, 'already have user') || str_contains($resMsg, 'already exists')) {
                            $resId = $res[0]['ret'] ?? $res[0]['.id'] ?? 'existing';
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
