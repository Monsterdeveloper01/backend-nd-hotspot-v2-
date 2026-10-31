<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherPlan;
use App\Services\AutoDebitService;
use App\Services\IncidentLoggerService;
use App\Services\MikrotikService;
use App\Services\PhoneNumberService;
use App\Services\PointSecurityService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\CoreApi;

class PointCustomerController extends Controller
{
    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Available Top-Up ND-Points Packages
     */
    public static function getAvailablePackages(): array
    {
        return [
            [
                'id' => 'pts_10k',
                'name' => 'Paket Hemat',
                'price' => 10000,
                'points' => 10000,
                'bonus_points' => 0,
                'total_points' => 10000,
                'badge' => null,
                'is_popular' => false,
            ],
            [
                'id' => 'pts_20k',
                'name' => 'Paket Populer',
                'price' => 20000,
                'points' => 20000,
                'bonus_points' => 1000,
                'total_points' => 21000,
                'badge' => '+1.000 Bonus',
                'is_popular' => true,
            ],
            [
                'id' => 'pts_50k',
                'name' => 'Paket Sultan',
                'price' => 50000,
                'points' => 50000,
                'bonus_points' => 5000,
                'total_points' => 55000,
                'badge' => '+5.000 Bonus',
                'is_popular' => false,
            ],
            [
                'id' => 'pts_100k',
                'name' => 'Paket Juara',
                'price' => 100000,
                'points' => 100000,
                'bonus_points' => 15000,
                'total_points' => 115000,
                'badge' => '+15.000 Bonus',
                'is_popular' => false,
            ],
        ];
    }

    /**
     * Get list of topup packages (Public)
     */
    public function packages()
    {
        return response()->json([
            'success' => true,
            'packages' => self::getAvailablePackages(),
            'terms' => [
                'closed_loop' => true,
                'non_refundable' => true,
                'notice' => 'ND-Points adalah sistem koin tertutup yang hanya dapat digunakan untuk pembelian voucher internet ND-Hotspot dan tidak dapat diuangkan/di-refund kembali.',
            ],
        ]);
    }

    /**
     * Check customer account status (Public, Rate Limited)
     */
    public function checkAccount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first('phone'),
            ], 422);
        }

        $res = PointSecurityService::getAccountStatus($request->input('phone'));
        return response()->json($res);
    }

    /**
     * Request OTP via WhatsApp (Public, Rate Limited)
     */
    public function requestOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
            'action' => 'nullable|string|in:setup_pin,reset_pin',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $action = $request->input('action', 'setup_pin');
        $res = PointSecurityService::requestOtp($request->input('phone'), $action);

        $status = $res['success'] ? 200 : 429;
        return response()->json($res, $status);
    }

    /**
     * Verify OTP and Setup / Reset PIN (Public, Rate Limited)
     */
    public function setupPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
            'otp' => 'required|string|min:4|max:6',
            'pin' => 'required|string|regex:/^[0-9]{6}$/',
            'action' => 'nullable|string|in:setup_pin,reset_pin',
        ], [
            'pin.regex' => 'PIN harus terdiri dari 6 angka numerik.',
            'otp.required' => 'Kode OTP wajib diisi.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $action = $request->input('action', 'setup_pin');
        $res = PointSecurityService::setupPinWithOtp(
            $request->input('phone'),
            $request->input('otp'),
            $request->input('pin'),
            $action
        );

        $status = $res['success'] ? 200 : 422;
        return response()->json($res, $status);
    }

    /**
     * Verify PIN directly (e.g. for pre-checking)
     */
    public function verifyPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
            'pin' => 'required|string|regex:/^[0-9]{6}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $res = PointSecurityService::verifyPin($request->input('phone'), $request->input('pin'));
        $status = $res['success'] ? 200 : 401;
        return response()->json($res, $status);
    }

    /**
     * Redeem Voucher using ND-Points (1-Click, PIN protected)
     */
    public function redeemVoucher(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
            'pin' => 'required|string|regex:/^[0-9]{6}$/',
            'voucher_plan_id' => 'required|exists:voucher_plans,id',
        ], [
            'pin.regex' => 'PIN harus terdiri dari 6 angka.',
            'voucher_plan_id.exists' => 'Paket voucher yang dipilih tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $normalizedPhone = PhoneNumberService::normalize($request->input('phone'));
        if (!$normalizedPhone) {
            return response()->json([
                'success' => false,
                'message' => 'Format nomor WhatsApp tidak valid.',
            ], 422);
        }

        // 1. Verify PIN and Lockout status
        $verifyRes = PointSecurityService::verifyPin($normalizedPhone, $request->input('pin'));
        if (!$verifyRes['success']) {
            IncidentLoggerService::report(
                'point_fraud',
                'warning',
                'Percobaan Tukar Poin Ditolak (PIN Tidak Valid)',
                "Nomor {$normalizedPhone} mencoba menukar voucher tetapi verifikasi PIN gagal: " . ($verifyRes['message'] ?? 'PIN Salah'),
                ['phone' => $normalizedPhone, 'voucher_plan_id' => $request->input('voucher_plan_id')]
            );
            return response()->json($verifyRes, 401);
        }

        // 2. Load voucher plan
        $plan = VoucherPlan::findOrFail($request->input('voucher_plan_id'));
        $pointsRequired = (int) $plan->price; // 1 Poin = Rp 1

        // 3. Atomic redemption inside DB transaction with row locking
        try {
            $result = DB::transaction(function () use ($normalizedPhone, $plan, $pointsRequired) {
                // Lock point account row
                $account = PointAccount::where('phone', $normalizedPhone)->lockForUpdate()->first();
                if (!$account) {
                    throw new \RuntimeException('Akun ND-Points tidak ditemukan.');
                }

                if ($account->balance < $pointsRequired) {
                    throw new \RuntimeException("Saldo poin tidak mencukupi. Diperlukan {$pointsRequired} poin, saldo Anda: {$account->balance} poin.");
                }

                $balanceBefore = (int) $account->balance;
                $balanceAfter = $balanceBefore - $pointsRequired;

                // Deduct points
                $account->balance = $balanceAfter;
                $account->lifetime_spent += $pointsRequired;
                $account->save();

                // Assign or generate voucher
                $voucher = Voucher::where('voucher_plan_id', $plan->id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->first();

                if (!$voucher) {
                    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
                    $voucherCode = substr(str_shuffle(str_repeat($chars, 6)), 0, 6);
                    $mikrotikId = null;

                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik') {
                        $mikrotikResult = $this->mikrotik->createUser([
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
                        'customer_phone' => $normalizedPhone,
                        'mikrotik_id' => $mikrotikId,
                    ]);
                } else {
                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik' && !$voucher->mikrotik_id) {
                        $this->mikrotik->createUser([
                            'username' => $voucher->code,
                            'password' => '',
                            'profile' => $plan->name,
                            'limit_uptime' => $plan->duration ?: '0',
                        ]);
                    }

                    $voucher->status = 'sold';
                    $voucher->customer_phone = $normalizedPhone;
                    $voucher->save();
                }

                // Create Transaction record for history (Distinguishable ID: PTS-REDEEM-)
                $orderId = 'PTS-REDEEM-' . time() . '-' . strtoupper(Str::random(4));
                $tx = Transaction::create([
                    'external_id' => $orderId,
                    'voucher_plan_id' => $plan->id,
                    'customer_phone' => $normalizedPhone,
                    'amount' => 0,
                    'payment_type' => 'nd_points',
                    'status' => 'success',
                    'voucher_id' => $voucher->id,
                ]);

                // Create Point Ledger Transaction
                PointTransaction::create([
                    'point_account_id' => $account->id,
                    'phone' => $normalizedPhone,
                    'type' => 'spend',
                    'source_type' => 'voucher_redemption',
                    'source_id' => (string) $tx->id,
                    'points' => -$pointsRequired,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => 'Tukar Poin: Paket ' . $plan->name,
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
                    'plan' => $plan,
                    'balance_after' => $balanceAfter,
                    'points_spent' => $pointsRequired,
                    'order_id' => $orderId,
                ];
            });

            // Send WhatsApp Notification
            try {
                $voucher = $result['voucher'];
                $plan = $result['plan'];
                $balanceAfterFormatted = number_format($result['balance_after'], 0, ',', '.');
                $pointsSpentFormatted = number_format($result['points_spent'], 0, ',', '.');

                $waMsg = "🎉 *VOUCHER BERHASIL DITUKAR (ND-POINTS)*\n\n"
                    . "Paket: *{$plan->name}*\n"
                    . "Kode Voucher: *{$voucher->code}*\n"
                    . "Durasi: *{$plan->duration}*\n"
                    . "Poin Terpotong: *{$pointsSpentFormatted} Poin*\n"
                    . "Sisa Saldo Poin: *{$balanceAfterFormatted} Poin*\n\n"
                    . "🌐 *Login Otomatis:* \n"
                    . "http://ndnet.login/login?username={$voucher->code}&password=\n\n"
                    . "Terima kasih telah setia menggunakan layanan ND-Hotspot!";

                app(WhatsAppService::class)->sendMessage($normalizedPhone, $waMsg, 'Tukar Poin Voucher');
            } catch (\Throwable $we) {
                Log::warning('PointCustomerController: WA notification failed: ' . $we->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Voucher berhasil ditukar menggunakan ND-Points!',
                'data' => [
                    'order_id' => $result['order_id'],
                    'voucher_code' => $result['voucher']->code,
                    'plan_name' => $result['plan']->name,
                    'duration' => $result['plan']->duration,
                    'speed' => $result['plan']->speed_limit ?? 'Standard',
                    'points_spent' => $result['points_spent'],
                    'balance_remaining' => $result['balance_after'],
                    'login_url' => "http://ndnet.login/login?username={$result['voucher']->code}&password=&dst=" . urlencode(env('APP_URL', 'https://nd-hostpot.net')),
                ],
            ]);

        } catch (\RuntimeException $re) {
            return response()->json([
                'success' => false,
                'message' => $re->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('PointCustomerController: Error redeeming voucher: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            IncidentLoggerService::log(
                'point_redemption',
                'critical',
                'Gagal Tukar Poin Voucher',
                $e->getMessage(),
                ['phone' => $normalizedPhone, 'plan_id' => $plan->id]
            );

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem saat memproses voucher. Poin Anda tidak terpotong. Silakan coba sesaat lagi.',
            ], 500);
        }
    }

    /**
     * Generate Midtrans QRIS charge for ND-Points Top-Up
     */
    public function topupCharge(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
            'package_id' => 'required|string',
            'pin' => 'nullable|string|regex:/^[0-9]{6}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $normalizedPhone = PhoneNumberService::normalize($request->input('phone'));
        if (!$normalizedPhone) {
            return response()->json([
                'success' => false,
                'message' => 'Format nomor WhatsApp tidak valid.',
            ], 422);
        }

        // Check if account has PIN. If so, enforce PIN verification
        $account = PointAccount::where('phone', $normalizedPhone)->first();
        if ($account && $account->hasPin()) {
            if (!$request->filled('pin')) {
                return response()->json([
                    'success' => false,
                    'require_pin' => true,
                    'message' => 'Silakan masukkan PIN keamanan Anda untuk melanjutkan top-up.',
                ], 403);
            }

            $pinCheck = PointSecurityService::verifyPin($normalizedPhone, $request->input('pin'));
            if (!$pinCheck['success']) {
                return response()->json($pinCheck, 401);
            }
        }

        // Find package
        $packages = self::getAvailablePackages();
        $selected = null;
        foreach ($packages as $pkg) {
            if ($pkg['id'] === $request->input('package_id')) {
                $selected = $pkg;
                break;
            }
        }

        if (!$selected) {
            return response()->json([
                'success' => false,
                'message' => 'Paket Top-Up tidak valid.',
            ], 422);
        }

        // Configure Midtrans
        Config::$serverKey = env('MIDTRANS_SERVER_KEY');
        Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        Config::$isSanitized = true;
        Config::$is3ds = true;

        $orderId = 'PTS-TOPUP-' . time() . '-' . rand(100, 999);
        $amount = (int) $selected['price'];

        $params = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => 'TopUp ND-Points',
                'phone' => $normalizedPhone,
            ],
            'item_details' => [
                [
                    'id' => $selected['id'],
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => "TopUp {$selected['total_points']} ND-Points",
                ],
            ],
        ];

        try {
            $response = CoreApi::charge($params);

            $qrisUrl = null;
            $qrString = null;

            if (isset($response->actions)) {
                foreach ($response->actions as $action) {
                    if ($action->name === 'generate-qr-code') {
                        $qrisUrl = $action->url;
                    }
                }
            }
            if (isset($response->qr_string)) {
                $qrString = $response->qr_string;
            }

            // Create pending transaction in transactions table (Payment type: point_topup)
            $tx = Transaction::create([
                'external_id' => $orderId,
                'customer_phone' => $normalizedPhone,
                'amount' => $amount,
                'payment_type' => 'point_topup',
                'status' => 'pending',
                'qr_string' => $qrString,
                'snap_token' => $qrisUrl,
            ]);

            return response()->json([
                'success' => true,
                'transaction_id' => $tx->id,
                'order_id' => $orderId,
                'payment_url' => $qrisUrl,
                'qris_url' => $qrisUrl,
                'qr_string' => $qrString,
                'amount' => $amount,
                'package' => $selected,
            ]);

        } catch (\Throwable $e) {
            Log::error('PointCustomerController: Midtrans charge error: ' . $e->getMessage());
            IncidentLoggerService::report(
                'point_fraud',
                'critical',
                'Gagal Inisiasi Top-Up Midtrans QRIS',
                "Error generate QRIS top-up untuk nomor {$normalizedPhone}: " . $e->getMessage(),
                ['phone' => $normalizedPhone, 'amount' => $amount]
            );
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat QRIS Top-Up: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Auto-Debit configuration for customer
     */
    public function getAutoDebit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $phone = PhoneNumberService::normalize($request->input('phone'));
        $account = PointAccount::with('autoDebitPlan')->where('phone', $phone)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Akun tidak ditemukan.'], 404);
        }

        return response()->json([
            'success' => true,
            'auto_debit_enabled' => (bool) $account->auto_debit_enabled,
            'auto_debit_plan_id' => $account->auto_debit_plan_id,
            'auto_debit_plan' => $account->autoDebitPlan,
            'auto_debit_trigger_type' => $account->auto_debit_trigger_type ?: 'on_expiry',
            'auto_debit_day' => (int) ($account->auto_debit_day ?: 1),
            'last_run_at' => $account->auto_debit_last_run_at ? $account->auto_debit_last_run_at->format('Y-m-d H:i:s') : null,
            'fail_count' => (int) $account->auto_debit_fail_count,
        ]);
    }

    /**
     * Configure Auto-Debit (Enable/Disable & Select Plan and Schedule)
     */
    public function toggleAutoDebit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
            'pin' => 'required|string|regex:/^[0-9]{6}$/',
            'enabled' => 'required|boolean',
            'voucher_plan_id' => 'nullable|exists:voucher_plans,id',
            'trigger_type' => 'nullable|string|in:on_expiry,monthly_date',
            'day' => 'nullable|integer|min:1|max:31',
        ], [
            'pin.regex' => 'PIN harus terdiri dari 6 angka.',
            'voucher_plan_id.exists' => 'Paket voucher pilihan tidak valid.',
            'trigger_type.in' => 'Pilihan jadwal ND Auto-Voucher tidak valid.',
            'day.min' => 'Tanggal perpanjangan minimal tanggal 1.',
            'day.max' => 'Tanggal perpanjangan maksimal tanggal 31.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $phone = PhoneNumberService::normalize($request->input('phone'));
        $pinCheck = PointSecurityService::verifyPin($phone, $request->input('pin'));
        if (!$pinCheck['success']) {
            IncidentLoggerService::report(
                'point_fraud',
                'warning',
                'Percobaan Konfigurasi ND Auto-Voucher Ditolak (PIN Salah)',
                "Nomor {$phone} gagal verifikasi PIN saat mencoba mengubah pengaturan ND Auto-Voucher.",
                ['phone' => $phone]
            );
            return response()->json($pinCheck, 401);
        }

        $account = PointAccount::where('phone', $phone)->first();
        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Akun tidak ditemukan.'], 404);
        }

        $enabled = $request->boolean('enabled');
        $planId = $request->input('voucher_plan_id');
        $triggerType = $request->input('trigger_type', 'on_expiry');
        $day = $request->input('day');

        if ($enabled && empty($planId)) {
            return response()->json([
                'success' => false,
                'message' => 'Harap pilih paket voucher untuk perpanjangan otomatis.',
            ], 422);
        }

        if ($enabled && $triggerType === 'monthly_date' && (empty($day) || $day < 1 || $day > 31)) {
            return response()->json([
                'success' => false,
                'message' => 'Harap tentukan tanggal pembelian bulanan (tanggal 1 - 31).',
            ], 422);
        }

        $account->auto_debit_enabled = $enabled;
        $account->auto_debit_plan_id = $enabled ? $planId : null;
        $account->auto_debit_trigger_type = $enabled ? $triggerType : 'on_expiry';
        $account->auto_debit_day = ($enabled && $triggerType === 'monthly_date') ? (int) $day : null;
        $account->auto_debit_fail_count = 0;
        $account->save();

        $account->load('autoDebitPlan');

        $initialVoucher = null;
        $message = $enabled
            ? "⚡ ND Auto-Voucher aktif untuk paket {$account->autoDebitPlan->name}."
            : 'ND Auto-Voucher berhasil dinonaktifkan.';

        // OPSI A: Jika user mengaktifkan mode 'on_expiry' dan belum memiliki voucher aktif,
        // sistem langsung membelikan voucher pertama saat itu juga!
        if ($enabled && $triggerType === 'on_expiry') {
            if (!AutoDebitService::hasActiveVoucher($phone)) {
                $pointsRequired = (int) ($account->autoDebitPlan->price ?? 0);
                if ($account->balance >= $pointsRequired) {
                    $autoDebitRes = AutoDebitService::processAccount($account);
                    if ($autoDebitRes['success']) {
                        $account->refresh();
                        $voucher = $autoDebitRes['data']['voucher'] ?? null;
                        if ($voucher) {
                            $initialVoucher = [
                                'voucher_code' => $voucher->code,
                                'plan_name' => $account->autoDebitPlan->name ?? 'Paket Hotspot',
                                'points_spent' => $pointsRequired,
                                'login_url' => 'http://ndnet.login/login?username=' . $voucher->code . '&password=',
                                'balance_after' => (int) $account->balance,
                            ];
                            $message = "⚡ ND Auto-Voucher aktif! Karena Anda belum memiliki voucher aktif, voucher pertama ({$voucher->code}) langsung diterbitkan dan dikirim ke WhatsApp Anda.";
                        }
                    }
                } else {
                    $message = "⚡ ND Auto-Voucher aktif untuk paket {$account->autoDebitPlan->name}. Voucher pertama akan otomatis dibeli begitu saldo poin Anda mencukupi.";
                }
            } else {
                $message = "⚡ ND Auto-Voucher aktif! Voucher baru akan otomatis dibeli begitu masa aktif voucher Anda saat ini telah habis.";
            }
        } elseif ($enabled && $triggerType === 'monthly_date') {
            $message = "⚡ ND Auto-Voucher aktif untuk paket {$account->autoDebitPlan->name} (setiap tanggal {$account->auto_debit_day}).";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'auto_debit_enabled' => (bool) $account->auto_debit_enabled,
            'auto_debit_plan' => $account->autoDebitPlan,
            'auto_debit_trigger_type' => $account->auto_debit_trigger_type,
            'auto_debit_day' => $account->auto_debit_day,
            'initial_voucher' => $initialVoucher,
        ]);
    }
}

