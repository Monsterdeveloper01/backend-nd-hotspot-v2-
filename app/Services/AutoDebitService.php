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
            return ['success' => false, 'message' => 'Auto-debit tidak aktif pada akun ini.'];
        }

        $plan = VoucherPlan::find($account->auto_debit_plan_id);
        if (!$plan) {
            IncidentLoggerService::report(
                'point_fraud',
                'warning',
                'Auto-Debit Gagal: Paket Voucher Tidak Ditemukan',
                "Akun {$account->phone} memiliki auto-debit untuk plan ID {$account->auto_debit_plan_id} yang sudah tidak ada.",
                ['phone' => $account->phone, 'plan_id' => $account->auto_debit_plan_id]
            );
            return ['success' => false, 'message' => 'Paket voucher auto-debit tidak ditemukan.'];
        }

        $pointsRequired = (int) $plan->price;

        // Check if balance is sufficient
        if ($account->balance < $pointsRequired) {
            $account->increment('auto_debit_fail_count');

            IncidentLoggerService::report(
                'point_fraud',
                'warning',
                'Auto-Debit Ditolak (Saldo Poin Kurang)',
                "Nomor {$account->phone} gagal perpanjang paket {$plan->name}. Saldo: {$account->balance} Pts, Diperlukan: {$pointsRequired} Pts.",
                [
                    'phone' => $account->phone,
                    'balance' => $account->balance,
                    'required' => $pointsRequired,
                    'fail_count' => $account->auto_debit_fail_count,
                ]
            );

            // Send friendly WhatsApp notice
            try {
                $msg = "⚠️ *PEMBERITAHUAN AUTO-DEBIT ND-HOTSPOT*\n\n"
                    . "Halo! Perpanjangan otomatis paket *{$plan->name}* tidak dapat diproses karena saldo ND-Points Anda tidak mencukupi.\n\n"
                    . "Saldo Anda: *{$account->balance} Poin*\n"
                    . "Diperlukan: *{$pointsRequired} Poin*\n\n"
                    . "Silakan lakukan Top-Up ND-Points di portal https://nd-hostpot.net/points agar auto-debit dapat berjalan kembali.";

                app(WhatsAppService::class)->sendMessage($account->phone, $msg, 'Auto-Debit Saldo Kurang');
            } catch (\Throwable $we) {
                Log::warning('AutoDebit: WA notice failed: ' . $we->getMessage());
            }

            return ['success' => false, 'message' => 'Saldo poin tidak mencukupi untuk auto-debit.'];
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
                    'description' => '⚡ Auto-Debit Perpanjangan: ' . $plan->name,
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

                $msg = "⚡ *AUTO-DEBIT ND-HOTSPOT BERHASIL!*\n\n"
                    . "Paket: *{$plan->name}*\n"
                    . "Durasi: *{$plan->duration}*\n"
                    . "Kode Voucher: *{$voucher->code}*\n"
                    . "Poin Terpotong: *{$ptsFormatted} Pts*\n"
                    . "Sisa Saldo Poin: *{$balFormatted} Poin*\n\n"
                    . "🌐 *Login Otomatis:* \n"
                    . "http://ndnet.login/login?username={$voucher->code}&password=\n\n"
                    . "Layanan internet Anda telah diperpanjang otomatis secara instan. Terima kasih!";

                app(WhatsAppService::class)->sendMessage($account->phone, $msg, 'Auto-Debit Sukses');
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
                'FATAL ERROR MESIN AUTO-DEBIT',
                "Gagal eksekusi auto-debit untuk nomor {$account->phone}: " . $e->getMessage(),
                ['phone' => $account->phone, 'plan_id' => $plan->id, 'error' => $e->getMessage()]
            );

            return ['success' => false, 'message' => 'Gagal memproses auto-debit: ' . $e->getMessage()];
        }
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
        $successCount = 0;
        $failCount = 0;

        foreach ($accounts as $acc) {
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
            'processed' => $processed,
            'success' => $successCount,
            'failed' => $failCount,
        ];
    }
}

