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
                    $voucherCode = substr(str_shuffle(str_repeat($chars, 6)), 0, 6);
                    $mikrotikId = null;

                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik') {
                        $mikrotikResult = $mikrotikService->createUser([
                            'username' => $voucherCode,
                            'password' => '',
                            'profile' => $plan->mikrotik_profile ?: $plan->name,
                            'limit_uptime' => $plan->duration ?: '0',
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
                    ]);
                } else {
                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik' && !$voucher->mikrotik_id) {
                        $mikrotikService->createUser([
                            'username' => $voucher->code,
                            'password' => '',
                            'profile' => $plan->name,
                            'limit_uptime' => $plan->duration ?: '0',
                        ]);
                    }

                    $voucher->status = 'sold';
                    $voucher->customer_phone = $acc->phone;
                    $voucher->save();
                }

                $orderId = 'PTS-AUTODEBIT-' . time() . '-' . strtoupper(Str::random(4));
                $tx = Transaction::create([
                    'external_id' => $orderId,
                    'voucher_plan_id' => $plan->id,
                    'customer_phone' => $acc->phone,
                    'amount' => 0,
                    'payment_type' => 'nd_points_autodebit',
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

                $msg = "⚡ *ND AUTO-VOUCHER ND-HOTSPOT BERHASIL!*\n\n"
                    . "Paket: *{$plan->name}*\n"
                    . "Durasi: *{$plan->duration}*\n"
                    . "Kode Voucher: *{$voucher->code}*\n"
                    . "Poin Terpotong: *{$ptsFormatted} Pts*\n"
                    . "Sisa Saldo Poin: *{$balFormatted} Poin*\n\n"
                    . "🌐 *Login Otomatis:* \n"
                    . "http://ndnet.login/login?username={$voucher->code}&password=\n\n"
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
     * Check if a customer phone currently has an active, valid hotspot voucher.
     * Checks all vouchers owned by the phone (both bought via QRIS, Points, or Admin).
     *
     * @param string $phone
     * @return bool
     */
    public static function hasActiveVoucher(string $phone): bool
    {
        $normalized = PhoneNumberService::normalize($phone) ?? preg_replace('/[^0-9]/', '', $phone);
        
        // Build all possible phone number format variants (08xx, 628xx, +628xx, 8xx)
        // to guarantee matching vouchers purchased through QRIS, web, or WhatsApp bot
        $phoneVariants = array_unique(array_filter([
            $phone,
            $normalized,
            str_starts_with($normalized, '62') ? '0' . substr($normalized, 2) : null,
            '+' . $normalized,
            str_starts_with($normalized, '62') ? substr($normalized, 2) : null,
        ]));

        $latestVoucher = Voucher::whereIn('customer_phone', $phoneVariants)
            ->whereIn('status', ['sold', 'used'])
            ->latest('id')
            ->first();

        if (!$latestVoucher) {
            return false;
        }

        // If it has expires_at set, check if it's still unexpired in the future
        if ($latestVoucher->expires_at) {
            return $latestVoucher->expires_at->isFuture();
        }

        // If it is 'sold' and not yet used (first login pending),
        // consider active if created recently (within 7 days)
        if ($latestVoucher->status === 'sold' && !$latestVoucher->used_at) {
            return $latestVoucher->created_at && $latestVoucher->created_at->gt(now()->subDays(7));
        }

        return false;
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

