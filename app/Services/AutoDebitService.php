<?php

namespace App\Services;

use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoDebitService
{
    /**
     * Process auto-debit for a single account.
     *
     * @param PointAccount $account
     * @return array [success => bool, message => string]
     */
    public static function processAccount(PointAccount $account): array
    {
        if (!$account->auto_debit_enabled || empty($account->auto_debit_plan_id)) {
            return ['success' => false, 'message' => 'ND Auto-Voucher tidak aktif pada akun ini.'];
        }

        $plan = VoucherPlan::find($account->auto_debit_plan_id);
        if (!$plan) {
            IncidentLoggerService::report(
                'point_fraud',
                'warning',
                'ND Auto-Voucher Gagal: Paket Voucher Tidak Ditemukan',
                "Akun {$account->phone} memiliki jadwal ND Auto-Voucher untuk plan ID {$account->auto_debit_plan_id} yang sudah tidak ada.",
                ['phone' => $account->phone, 'plan_id' => $account->auto_debit_plan_id]
            );
            return ['success' => false, 'message' => 'Paket voucher ND Auto-Voucher tidak ditemukan.'];
        }

        $pointsRequired = (int) $plan->price;

        // Check if balance is sufficient
        if ($account->balance < $pointsRequired) {
            $account->increment('auto_debit_fail_count');

            IncidentLoggerService::report(
                'point_fraud',
                'warning',
                'ND Auto-Voucher Ditolak (Saldo Poin Kurang)',
                "Nomor {$account->phone} gagal perpanjang paket {$plan->name}. Saldo: {$account->balance} Pts, Diperlukan: {$pointsRequired} Pts.",
                [
                    'phone' => $account->phone,
                    'balance' => $account->balance,
                    'required' => $pointsRequired,
                    'fail_count' => $account->auto_debit_fail_count,
                    'trigger_type' => $account->auto_debit_trigger_type,
                ]
            );

            // Send friendly WhatsApp notice (only once per failure cycle to prevent notification spam)
            if ($account->auto_debit_fail_count <= 1 || $account->auto_debit_fail_count % 144 === 0) {
                try {
                    $msg = "⚠️ *PEMBERITAHUAN ND AUTO-VOUCHER ND-HOTSPOT*\n\n"
                        . "Halo! Perpanjangan otomatis ND Auto-Voucher paket *{$plan->name}* tidak dapat diproses karena saldo ND-Points Anda tidak mencukupi.\n\n"
                        . "Saldo Anda: *{$account->balance} Poin*\n"
                        . "Diperlukan: *{$pointsRequired} Poin*\n\n"
                        . "Silakan lakukan Top-Up ND-Points di portal https://nd-hostpot.net/points agar ND Auto-Voucher dapat berjalan kembali.";

                    app(WhatsAppService::class)->sendMessage($account->phone, $msg, 'ND Auto-Voucher Saldo Kurang');
                } catch (\Throwable $we) {
                    Log::warning('AutoDebit: WA notice failed: ' . $we->getMessage());
                }
            }

            return ['success' => false, 'message' => 'Saldo poin tidak mencukupi untuk ND Auto-Voucher.'];
        }

        // Execute transaction atomically
        try {
            $result = DB::transaction(function () use ($account, $plan, $pointsRequired) {
                // Lock account
                $acc = PointAccount::where('id', $account->id)->lockForUpdate()->first();
                if ($acc->balance < $pointsRequired) {
                    throw new \RuntimeException('Saldo poin berkurang saat proses berlangsung.');
                }

                $balanceBefore = (int) $acc->balance;
                $balanceAfter = $balanceBefore - $pointsRequired;

                $acc->balance = $balanceAfter;
                $acc->lifetime_spent += $pointsRequired;
                $acc->auto_debit_last_run_at = now();
                $acc->auto_debit_fail_count = 0;
                $acc->save();

                // Assign or generate voucher
                $voucher = Voucher::where('voucher_plan_id', $plan->id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->first();

                $mikrotikService = app(MikrotikService::class);

                if (!$voucher) {
                    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
                    do {
                        $voucherCode = substr(str_shuffle(str_repeat($chars, 6)), 0, 6);
                    } while (Voucher::where('code', $voucherCode)->exists());
                    $mikrotikId = null;

                    $voucherMode = config('mikrotik.voucher_mode', env('VOUCHER_MODE', 'mikrotik'));
                    if ($voucherMode === 'mikrotik') {
                        $mikrotikResult = $mikrotikService->createUser([
                            'username' => $voucherCode,
                            'password' => '',
                            'profile' => $plan->mikrotik_profile ?: $plan->name,
                            'limit_uptime' => $plan->duration ?: '0',
                            'comment' => "ND-AUTOVOUCHER: Exp 3 Hari ({$voucherCode})",
                        ]);

                        if (is_array($mikrotikResult) && isset($mikrotikResult[0]['.id'])) {
                            $mikrotikId = $mikrotikResult[0]['.id'];
                        }
                    }

                    $voucher = Voucher::create([
                        'voucher_plan_id' => $plan->id,
                        'code' => $voucherCode,
                        'price' => $plan->price,
                        'status' => 'sold',
                        'customer_phone' => $acc->phone,
                        'mikrotik_id' => $mikrotikId,
                        'expires_at' => now()->addDays(3),
                    ]);
                } else {
                    $voucherMode = config('mikrotik.voucher_mode', env('VOUCHER_MODE', 'mikrotik'));
                    if ($voucherMode === 'mikrotik') {
                        if (!$voucher->mikrotik_id) {
                            $mikrotikService->createUser([
                                'username' => $voucher->code,
                                'password' => '',
                                'profile' => $plan->mikrotik_profile ?: $plan->name,
                                'limit_uptime' => $plan->duration ?: '0',
                                'comment' => "ND-AUTOVOUCHER: Exp 3 Hari ({$voucher->code})",
                            ]);
                        } else {
                            try {
                                $mikrotikService->comm('/ip/hotspot/user/set', [
                                    '.id' => $voucher->mikrotik_id,
                                    'comment' => "ND-AUTOVOUCHER: Exp 3 Hari ({$voucher->code})",
                                ]);
                            } catch (\Throwable $e) {}
                        }
                    }

                    $voucher->status = 'sold';
                    $voucher->customer_phone = $acc->phone;
                    $voucher->expires_at = now()->addDays(3);
                    $voucher->save();
                }

                $orderId = 'PTS-AUTODEBIT-' . time() . '-' . strtoupper(Str::random(4));
                $tx = Transaction::create([
                    'external_id' => $orderId,
                    'voucher_plan_id' => $plan->id,
                    'customer_phone' => $acc->phone,
                    'amount' => 0,
                    'payment_method' => 'nd_points_autodebit',
                    'status' => 'success',
                    'voucher_id' => $voucher->id,
                ]);

                PointTransaction::create([
                    'point_account_id' => $acc->id,
                    'phone' => $acc->phone,
                    'type' => 'spend',
                    'source_type' => 'auto_debit',
                    'source_id' => (string) $tx->id,
                    'points' => -$pointsRequired,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => '⚡ ND Auto-Voucher Perpanjangan: ' . $plan->name,
                    'metadata' => [
                        'voucher_plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                        'voucher_code' => $voucher->code,
                        'transaction_id' => $tx->id,
                        'order_id' => $orderId,
                    ],
                ]);

                return [
                    'voucher' => $voucher,
                    'order_id' => $orderId,
                    'balance_after' => $balanceAfter,
                ];
            });

            // Send WhatsApp Success Notification
            try {
                $voucher = $result['voucher'];
                $balFormatted = number_format($result['balance_after'], 0, ',', '.');
                $ptsFormatted = number_format($pointsRequired, 0, ',', '.');
                $expFormatted = $voucher->expires_at ? $voucher->expires_at->translatedFormat('d M Y, H:i') : now()->addDays(3)->translatedFormat('d M Y, H:i');

                $msg = "⚡ *ND AUTO-VOUCHER ND-HOTSPOT BERHASIL!*\n\n"
                    . "Paket: *{$plan->name}*\n"
                    . "Durasi: *{$plan->duration}*\n"
                    . "Kode Voucher: *{$voucher->code}*\n"
                    . "Poin Terpotong: *{$ptsFormatted} Pts*\n"
                    . "Sisa Saldo Poin: *{$balFormatted} Poin*\n"
                    . "⏳ *Masa Berlaku Voucher:* *3 HARI* (Kedaluwarsa: {$expFormatted} WIB)\n"
                    . "⚠️ _Perhatian: Voucher ini memiliki batas waktu 3 hari. Segera gunakan sebelum kedaluwarsa & voucher otomatis terhapus._\n\n"
                    . "🌐 *Login Otomatis:* \n"
                    . "http://ndnet.login/login?username={$voucher->code}&password=\n"
                    . "_(Pastikan terhubung ke WiFi ND-Hotspot. Jika link belum bisa dipencet, balas chat ini atau simpan kontak ini)_\n\n"
                    . "Layanan internet Anda telah diperpanjang otomatis secara instan via ND Auto-Voucher. Terima kasih!";

                app(WhatsAppService::class)->sendMessage($account->phone, $msg, 'ND Auto-Voucher Sukses');
            } catch (\Throwable $we) {
                Log::warning('AutoDebit: WA success notification failed: ' . $we->getMessage());
            }

            return ['success' => true, 'data' => $result];

        } catch (\Throwable $e) {
            Log::error('AutoDebit: Critical failure: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            IncidentLoggerService::report(
                'point_fraud',
                'critical',
                'FATAL ERROR MESIN ND AUTO-VOUCHER',
                "Gagal eksekusi ND Auto-Voucher untuk nomor {$account->phone}: " . $e->getMessage(),
                ['phone' => $account->phone, 'plan_id' => $plan->id, 'error' => $e->getMessage()]
            );

            return ['success' => false, 'message' => 'Gagal memproses ND Auto-Voucher: ' . $e->getMessage()];
        }
    }

    /**
     * Build all possible phone number format variants (08xx, 628xx, +628xx, 8xx)
     * to guarantee matching vouchers purchased through QRIS, web, or WhatsApp bot.
     */
    public static function getPhoneVariants(string $phone): array
    {
        $normalized = PhoneNumberService::normalize($phone) ?? preg_replace('/[^0-9]/', '', $phone);

        return array_unique(array_filter([
            $phone,
            $normalized,
            str_starts_with($normalized, '62') ? '0' . substr($normalized, 2) : null,
            '+' . $normalized,
            str_starts_with($normalized, '62') ? substr($normalized, 2) : null,
        ]));
    }

    /**
     * Check if a customer phone currently has an active, valid hotspot voucher.
     * Checks all vouchers owned by the phone (both bought via QRIS, Points, or Admin).
     *
     * @param string $phone
     * @return bool
     */
    public static function hasActiveVoucher(string $phone): bool
    {
        $phoneVariants = self::getPhoneVariants($phone);

        // Check if ANY voucher owned by this customer is currently active and unexpired
        return Voucher::whereIn('customer_phone', $phoneVariants)
            ->whereIn('status', ['sold', 'used'])
            ->where(function ($q) {
                // 1. Voucher sedang aktif dipakai dan belum habis durasi/paketnya
                $q->where(function ($q2) {
                    $q2->where('status', 'used')
                       ->where(function ($q3) {
                           $q3->whereNull('expires_at')
                              ->orWhere('expires_at', '>', now());
                       });
                })
                // 2. Atau voucher siap pakai (belum login pertama) dan masih dalam masa berlaku
                ->orWhere(function ($q2) {
                    $q2->where('status', 'sold')
                       ->where(function ($q3) {
                           // Voucher poin / reward (memiliki batas kedaluwarsa 3 atau 5 hari)
                           $q3->whereNotNull('expires_at')
                              ->where('expires_at', '>', now());
                       })
                       ->orWhere(function ($q3) {
                           // Voucher QRIS biasa (tanpa expires_at sebelum login, toleransi 7 hari)
                           $q3->whereNull('expires_at')
                              ->where('created_at', '>', now()->subDays(7));
                       });
                });
            })
            ->exists();
    }

    /**
     * Check if a specific account is currently eligible to be debited based on its schedule.
     *
     * @param PointAccount $account
     * @return array [eligible => bool, reason => string]
     */
    public static function isEligibleToRun(PointAccount $account): array
    {
        if (!$account->auto_debit_enabled) {
            return ['eligible' => false, 'reason' => 'ND Auto-Voucher dinonaktifkan'];
        }

        if ($account->status !== 'active') {
            return ['eligible' => false, 'reason' => 'Status akun ' . $account->status];
        }

        if (empty($account->auto_debit_plan_id)) {
            return ['eligible' => false, 'reason' => 'Paket voucher belum dipilih'];
        }

        $triggerType = $account->auto_debit_trigger_type ?: 'on_expiry';

        // MODE 1: Tanggal tertentu setiap bulan (e.g. tgl 1..31)
        if ($triggerType === 'monthly_date') {
            $targetDay = (int) ($account->auto_debit_day ?: 1);
            $daysInMonth = (int) now()->daysInMonth;
            $effectiveDay = min($targetDay, $daysInMonth);
            $currentDay = (int) now()->day;

            if ($currentDay !== $effectiveDay) {
                return [
                    'eligible' => false,
                    'reason' => "Menunggu tanggal {$effectiveDay} (hari ini tgl {$currentDay})",
                ];
            }

            // Check if already executed this month
            if ($account->auto_debit_last_run_at) {
                $lastRun = $account->auto_debit_last_run_at;
                if ($lastRun->isSameMonth(now()) && $lastRun->isSameYear(now())) {
                    return [
                        'eligible' => false,
                        'reason' => 'Sudah dieksekusi bulan ini pada ' . $lastRun->format('d/m/Y H:i'),
                    ];
                }
            }

            return ['eligible' => true, 'reason' => "Jadwal bulanan tanggal {$effectiveDay} terpenuhi"];
        }

        // MODE 2: Setelah voucher sebelumnya habis / kedaluwarsa (on_expiry)
        // Check if customer still has an active voucher
        if (self::hasActiveVoucher($account->phone)) {
            return [
                'eligible' => false,
                'reason' => 'Voucher sebelumnya masih aktif/berlaku',
            ];
        }

        // PROTEKSI OPSI 1:
        // Jika voucher terakhir kedaluwarsa dalam keadaan BELUM PERNAH digunakan sama sekali (used_at is null),
        // tahan / pause auto-debit agar poin pelanggan tidak hangus sia-sia saat tidak berada di lokasi WiFi.
        $phoneVariants = self::getPhoneVariants($account->phone);
        $latestVoucher = Voucher::whereIn('customer_phone', $phoneVariants)
            ->latest('id')
            ->first();

        if ($latestVoucher && empty($latestVoucher->used_at)) {
            $isExpiredUnused = false;
            if ($latestVoucher->status === 'expired') {
                $isExpiredUnused = true;
            } elseif ($latestVoucher->status === 'sold' && $latestVoucher->expires_at && $latestVoucher->expires_at->isPast()) {
                $isExpiredUnused = true;
            }

            // Izinkan berjalan jika pelanggan secara sadar baru saja mengubah/menyimpan pengaturan Auto-Voucher
            // setelah voucher tersebut kedaluwarsa
            $userExplicitlyUpdatedAfter = $latestVoucher->updated_at 
                && $account->updated_at 
                && $account->updated_at->gt($latestVoucher->updated_at);

            if ($isExpiredUnused && !$userExplicitlyUpdatedAfter) {
                return [
                    'eligible' => false,
                    'reason' => 'Voucher sebelumnya kedaluwarsa tanpa digunakan (Auto-Voucher di-pause untuk menghemat poin)',
                    'is_paused_unused' => true,
                ];
            }
        }

        // Prevent rapid re-debiting if it ran within the last 30 minutes
        if ($account->auto_debit_last_run_at && $account->auto_debit_last_run_at->gt(now()->subMinutes(30))) {
            return [
                'eligible' => false,
                'reason' => 'Baru saja dieksekusi pada ' . $account->auto_debit_last_run_at->format('H:i') . ' (cooldown 30m)',
            ];
        }

        return ['eligible' => true, 'reason' => 'Voucher sebelumnya telah habis/kedaluwarsa'];
    }

    /**
     * Run auto-debit for all eligible customers whose vouchers need renewal.
     */
    public static function runAll(): array
    {
        $accounts = PointAccount::where('auto_debit_enabled', true)
            ->where('status', 'active')
            ->whereNotNull('auto_debit_plan_id')
            ->get();

        $processed = 0;
        $skippedCount = 0;
        $successCount = 0;
        $failCount = 0;

        foreach ($accounts as $acc) {
            $eligibility = self::isEligibleToRun($acc);
            if (!$eligibility['eligible']) {
                $skippedCount++;
                continue;
            }

            $processed++;
            $res = self::processAccount($acc);
            if ($res['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        return [
            'total_scanned' => $accounts->count(),
            'skipped' => $skippedCount,
            'processed' => $processed,
            'success' => $successCount,
            'failed' => $failCount,
        ];
    }
}

