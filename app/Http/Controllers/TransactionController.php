<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherPlan;
use App\Models\Customer;
use App\Services\WhatsAppService;
use App\Services\EventAnalyticsService;
use App\Services\PointService;
use App\Services\MikrotikService;
use App\Services\IncidentLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Midtrans\Config;
use Midtrans\CoreApi;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class TransactionController extends Controller
{
    protected $wa;
    protected $mikrotik;
    protected $telegram;



    
    public function __construct(WhatsAppService $wa, MikrotikService $mikrotik, \App\Services\TelegramService $telegram)
    {
        $this->wa = $wa;
        $this->mikrotik = $mikrotik;
        $this->telegram = $telegram;
        Config::$serverKey = env('MIDTRANS_SERVER_KEY');
        Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'voucher_plan_id' => 'required|exists:voucher_plans,id',
            'phone' => 'required|string|min:10',
        ]);

        $plan = VoucherPlan::findOrFail($request->voucher_plan_id);
        $orderId = 'ND-' . time() . '-' . Str::upper(Str::random(4));

        $params = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int)$plan->price,
            ],
            'customer_details' => [
                'phone' => $request->phone,
            ],
        ];

        try {
            $response = \Midtrans\CoreApi::charge($params);
            
            // Get QRIS URL and QR String from response
            $qrisUrl = '';
            $qrString = $response->qr_string ?? ''; // Midtrans returns qr_string for QRIS
            
            if (isset($response->actions)) {
                foreach ($response->actions as $action) {
                    if ($action->name == 'generate-qr-code') {
                        $qrisUrl = $action->url;
                    }
                }
            }
            
            $transaction = Transaction::create([
                'external_id' => $orderId,
                'voucher_plan_id' => $plan->id,
                'customer_phone' => $request->phone,
                'amount' => $plan->price,
                'status' => 'pending',
                'payment_url' => $qrisUrl,
                'qr_string' => $qrString,
            ]);

            return response()->json([
                'success' => true,
                'transaction' => $transaction->load('plan')
            ]);
        } catch (\Exception $e) {
            IncidentLoggerService::report(
                'checkout',
                'critical',
                'Gagal Generate Pembayaran QRIS',
                "Gagal membuat transaksi CoreApi charge: " . $e->getMessage(),
                [
                    'voucher_plan_id' => $request->voucher_plan_id,
                    'phone'           => $request->phone,
                    'order_id'        => $orderId ?? null,
                    'ip'              => $request->ip(),
                ],
                $e->getTraceAsString(),
                'check_payment'
            );
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function generateAudioSequence($amount, $issuer)
    {
        $sequence = [];
        $sequence[] = 1; // "Diterima"

        // Mengubah nominal menjadi array urutan MP3 menggunakan fungsi Terbilang
        $audioNominal = $this->convertAngkaToAudioArray((int) $amount);
        $sequence = array_merge($sequence, $audioNominal);

        $sequence[] = 3; // "Rupiah"
        $sequence[] = 4; // "Dari"

        // Mapping Issuer Midtrans ke ID File MP3
        $issuerMap = [
            'gopay' => 5,
            'shopeepay' => 6,
            'bni' => 7,
            'qris' => 8 // Default QRIS
        ];

        $issuerKey = strtolower($issuer);
        $sequence[] = $issuerMap[$issuerKey] ?? 8;

        return implode(',', $sequence);
    }

    private function convertAngkaToAudioArray($angka) 
    {
        $angka = abs($angka);
        $audio = [];

        $satuan = [
            1 => 11, 2 => 12, 3 => 13, 4 => 14, 5 => 15, 
            6 => 16, 7 => 17, 8 => 18, 9 => 19
        ];

        if ($angka < 10) {
            if ($angka > 0) $audio[] = $satuan[$angka];
        } elseif ($angka == 10) {
            $audio[] = 20; // Sepuluh
        } elseif ($angka == 11) {
            $audio[] = 21; // Sebelas
        } elseif ($angka < 20) {
            $audio[] = $satuan[$angka - 10]; 
            $audio[] = 22; // Belas
        } elseif ($angka < 100) {
            $audio = array_merge($audio, $this->convertAngkaToAudioArray((int)($angka / 10)));
            $audio[] = 30; // Puluh
            $audio = array_merge($audio, $this->convertAngkaToAudioArray($angka % 10));
        } elseif ($angka < 200) {
            $audio[] = 41; // Seratus
            $audio = array_merge($audio, $this->convertAngkaToAudioArray($angka - 100));
        } elseif ($angka < 1000) {
            $audio = array_merge($audio, $this->convertAngkaToAudioArray((int)($angka / 100)));
            $audio[] = 40; // Ratus
            $audio = array_merge($audio, $this->convertAngkaToAudioArray($angka % 100));
        } elseif ($angka < 2000) {
            $audio[] = 51; // Seribu
            $audio = array_merge($audio, $this->convertAngkaToAudioArray($angka - 1000));
        } elseif ($angka < 1000000) {
            $audio = array_merge($audio, $this->convertAngkaToAudioArray((int)($angka / 1000)));
            $audio[] = 50; // Ribu
            $audio = array_merge($audio, $this->convertAngkaToAudioArray($angka % 1000));
        }

        return $audio;
    }

    public function callback(Request $request)
    {
        \Log::info('MIDTRANS CALLBACK RECEIVED', $request->all());

        try {
            $serverKey = env('MIDTRANS_SERVER_KEY');
            
            // Midtrans gross_amount sometimes comes with decimals (.00)
            // We must match the EXACT string sent by Midtrans for the signature hash
            $grossAmount = $request->gross_amount;
            $orderId = $request->order_id;
            $statusCode = $request->status_code;
            
            $hashed = hash("sha512", $orderId . $statusCode . $grossAmount . $serverKey);

            if ($hashed !== $request->signature_key) {
                \Log::error('MIDTRANS INVALID SIGNATURE', [
                    'expected' => $request->signature_key,
                    'calculated' => $hashed,
                    'payload' => $orderId . $statusCode . $grossAmount . $serverKey
                ]);

                IncidentLoggerService::logPaymentFraud(
                    'Percobaan Fraud: Midtrans Signature Palsu',
                    "Diterima webhook pembayaran mencurigakan dengan signature key yang tidak cocok untuk Order ID [{$orderId}].",
                    [
                        'order_id'       => $orderId,
                        'gross_amount'   => $grossAmount,
                        'status_code'    => $statusCode,
                        'ip_address'     => $request->ip(),
                        'received_sig'   => $request->signature_key,
                        'calculated_sig' => $hashed,
                    ]
                );

                return response()->json(['message' => 'Invalid signature'], 403);
            }

            if ($request->payment_type == 'qris' && in_array($request->transaction_status, ['settlement', 'capture'])) {
                
                $isSystemGenerated = Transaction::where('external_id', $orderId)->exists() || str_starts_with($orderId, 'BILL-');
                
                if (!$isSystemGenerated) {
                    \Log::info('STATIC QRIS PAYMENT RECEIVED', ['order_id' => $orderId, 'amount' => $grossAmount]);
                    
                    try {
                        Transaction::create([
                            'external_id' => $orderId,
                            'amount' => $grossAmount,
                            'status' => 'success',
                            'payment_method' => 'qris_statis',
                            'voucher_plan_id' => null,
                            'customer_phone' => null,
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('FAILED TO SAVE STATIC QRIS TO TRANSACTIONS', ['error' => $e->getMessage()]);
                    }
                    
                    $adminPhone = '628129588587'; 
                    $issuer = strtoupper($request->issuer ?? 'QRIS');
                    $amountFormatted = number_format($grossAmount, 0, ',', '.');
                    
                    // 1. Kirim WA ke Admin
                    $msgAdmin = "🔔 *PEMBAYARAN QRIS STATIS MASUK*\n\n" .
                                "Dana sebesar *Rp {$amountFormatted}* berhasil diterima.\n" .
                                "🏢 *Sumber:* {$issuer}\n" .
                                "🆔 *Order ID:* {$orderId}";
                    try {
                        $this->wa->sendMessage($adminPhone, $msgAdmin);
                    } catch (\Exception $e) {
                        \Log::error('WA ADMIN STATIC QRIS FAILED', ['error' => $e->getMessage()]);
                    }

                    // 2. Tembak Perintah ke Hardware ESP8266 via MQTT
                    try {
                        // Gunakan floatval/intval karena Midtrans mengirimkan format "50000.00"
                        $amountForHardware = (int) floatval($grossAmount);
                        $mqttPayload = $this->generateAudioSequence($amountForHardware, $request->issuer ?? 'qris');
                        
                        // Membuat Client ID unik agar webhook yang masuk bersamaan tidak saling menendang koneksi
                        $uniqueClientId = env('MQTT_CLIENT_ID', 'Laravel_Backend') . '_' . uniqid();
                        
                        $mqtt = new MqttClient(env('MQTT_HOST'), env('MQTT_PORT'), $uniqueClientId);
                        $mqtt->connect();
                        $mqtt->publish('qris/soundbox/midtrans', $mqttPayload, 0); 
                        $mqtt->disconnect();
                        
                        \Log::info('SOUNDBOX HARDWARE TRIGGERED', ['payload' => $mqttPayload, 'amount' => $amountForHardware]);
                    } catch (\Exception $e) {
                        \Log::error('MQTT PUBLISH FAILED', ['error' => $e->getMessage()]);
                    }

                    return response()->json(['message' => 'Static QRIS Handled and Hardware Triggered']);
                }
            }

        // Handle Customer Billing Payment
        if (str_starts_with($orderId, 'BILL-')) {
            \Log::info('PROCESSING BILL PAYMENT', ['order_id' => $orderId]);
            
            if ($request->transaction_status == 'settlement' || $request->transaction_status == 'capture') {
                $parts = explode('-', $orderId);
                $customerId = $parts[1];
                $customer = Customer::find($customerId);
                
                if (!$customer) {
                    \Log::error('CUSTOMER NOT FOUND', ['id' => $customerId]);
                    IncidentLoggerService::report(
                        'payment_fraud',
                        'warning',
                        'Pembayaran Tagihan: Customer Tidak Ditemukan',
                        "Diterima pembayaran sukses untuk Order ID [{$orderId}], namun Customer ID [{$customerId}] tidak ada di database.",
                        $request->all(),
                        null,
                        'check_payment'
                    );
                    return response()->json(['message' => 'Customer not found'], 404);
                }

                // Cek apakah transaksi ini sudah pernah dicatat sebelumnya
                $alreadyProcessed = Transaction::where('external_id', $orderId)->exists();

                if (!$alreadyProcessed) {
                    // Proses update data (hanya dilakukan 1x per Order ID)
                    $customer->status_bayar = 'paid';
                    $customer->is_isolated = false;
                    $customer->due_date = \Carbon\Carbon::parse($customer->due_date)->addMonth();
                    $customer->save();

                    Transaction::create([
                        'external_id' => $orderId,
                        'customer_phone' => $customer->whatsapp,
                        'amount' => $request->gross_amount,
                        'status' => 'success',
                        'voucher_plan_id' => null,
                    ]);
                    \Log::info('NEW TRANSACTION RECORDED', ['order_id' => $orderId]);
                } else {
                    \Log::info('TRANSACTION ALREADY RECORDED, ENSURING SERVICE IS ACTIVE', ['order_id' => $orderId]);
                }

                // --- BAGIAN INI AKAN SELALU JALAN (IDEMPOTENT) ---
                
                // 1. Re-enable in Mikrotik (Selalu pastikan aktif)
                $mikrotikResult = $this->mikrotik->setUserStatus($customer->name, true);
                \Log::info('MIKROTIK SYNC RESULT', ['success' => $mikrotikResult]);

                // 2. Send WA Receipt (Selalu kirim sebagai konfirmasi)
                try {
                    $date = \Carbon\Carbon::now()->format('d/m/Y');
                    $msg = "✅ *PEMBAYARAN BERHASIL*\n\n" .
                           "Halo *{$customer->name}*,\n" .
                           "Pembayaran tagihan internet Anda telah berhasil kami terima.\n\n" .
                           "💳 *Total:* Rp " . number_format($customer->billing_amount, 0, ',', '.') . "\n" .
                           "📅 *Tanggal:* {$date}\n" .
                           "💼 *Metode:* {$request->payment_type} (Portal Online)\n" .
                           "🆔 *Order ID:* {$orderId}\n\n" .
                           "✅ *Status Layanan: AKTIF*\n" .
                           "Jika internet belum terhubung, silakan:\n" .
                           "* Logout & Login ulang\n" .
                           "* Restart Modem/ONT\n\n" .
                           "Terima kasih! 🙏\n\n" .
                           "Hormat kami,\n" .
                           "*ND-Hotspot* 💡";

                    $this->wa->sendMessage($customer->whatsapp, $msg);
                    \Log::info('WA RECEIPT SENT');
                } catch (\Exception $e) {
                    \Log::error('WA SEND FAILED', ['error' => $e->getMessage()]);
                }
            }
            return response()->json(['message' => 'OK']);
        }

        // Handle Voucher Transaction
        $transaction = Transaction::where('external_id', $orderId)->first();
        
        if (!$transaction) {
            \Log::error('TRANSACTION NOT FOUND', ['external_id' => $orderId]);
            IncidentLoggerService::report(
                'payment_fraud',
                'warning',
                'Pembayaran Voucher: Order ID Tidak Ditemukan',
                "Diterima callback pembayaran Midtrans untuk Order [{$orderId}], namun external_id tidak ditemukan di database.",
                $request->all(),
                null,
                'check_payment'
            );
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        // Load plan relationship for name and duration
        $transaction->load('plan');
        
        if ($request->transaction_status == 'settlement' || $request->transaction_status == 'capture') {
            // Check if this is an ND-Points Top-Up transaction (Order ID starts with PTS-)
            if (str_starts_with($orderId, 'PTS-')) {
                DB::transaction(function () use ($transaction, $orderId) {
                    $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();
                    if (!$tx || $tx->status === 'success') {
                        return;
                    }

                    $amount = (int) floatval($tx->amount);
                    
                    // Determine points + bonus based on amount
                    $packages = \App\Http\Controllers\Api\PointCustomerController::getAvailablePackages();
                    $totalPoints = $amount;
                    $bonusPoints = 0;
                    foreach ($packages as $pkg) {
                        if ((int) $pkg['price'] === $amount) {
                            $totalPoints = (int) $pkg['total_points'];
                            $bonusPoints = (int) $pkg['bonus_points'];
                            break;
                        }
                    }

                    // Update or create point account
                    $phone = \App\Services\PhoneNumberService::normalize($tx->customer_phone);
                    $account = \App\Models\PointAccount::firstOrCreate(
                        ['phone' => $phone],
                        ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_spent' => 0, 'status' => 'active']
                    );
                    $account = \App\Models\PointAccount::where('id', $account->id)->lockForUpdate()->first();

                    $balanceBefore = (int) $account->balance;
                    $balanceAfter = $balanceBefore + $totalPoints;
                    $account->balance = $balanceAfter;
                    $account->lifetime_earned += $totalPoints;
                    $account->save();

                    // Record ledger
                    \App\Models\PointTransaction::create([
                        'point_account_id' => $account->id,
                        'phone' => $phone,
                        'type' => 'earn',
                        'source_type' => 'topup',
                        'source_id' => (string) $tx->id,
                        'points' => $totalPoints,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'description' => "Top-Up {$totalPoints} ND-Points (QRIS)",
                        'metadata' => [
                            'amount' => $amount,
                            'order_id' => $orderId,
                            'bonus_points' => $bonusPoints,
                        ],
                    ]);

                    $tx->status = 'success';
                    $tx->payment_method = 'point_topup';
                    $tx->save();

                    // Send WhatsApp Notification for Top-Up
                    try {
                        $amtFormatted = number_format($amount, 0, ',', '.');
                        $ptsFormatted = number_format($totalPoints, 0, ',', '.');
                        $balFormatted = number_format($balanceAfter, 0, ',', '.');

                        $msg = "🎉 *TOP-UP ND-POINTS BERHASIL!*\n\n"
                            . "Nomor: *{$phone}*\n"
                            . "Pembayaran: *Rp {$amtFormatted}*\n"
                            . "Poin Didapat: *+{$ptsFormatted} Poin*\n"
                            . "Total Saldo Poin: *{$balFormatted} Poin*\n\n"
                            . "Gunakan koin Anda kapan saja untuk membeli voucher internet instan di https://nd-hostpot.net tanpa perlu scan QRIS lagi.\n\n"
                            . "_Terima kasih telah menggunakan ND-Hotspot!_";

                        app(\App\Services\WhatsAppService::class)->sendMessage($phone, $msg, 'Top-Up Poin Sukses');
                    } catch (\Throwable $we) {
                        \Log::warning('WhatsApp topup notify error: ' . $we->getMessage());
                    }
                });

                return response()->json(['message' => 'Point topup callback processed successfully'], 200);
            }

            $isNewlySuccessful = false;
            $voucherForNotify = null;

            DB::transaction(function () use ($transaction, $orderId, &$isNewlySuccessful, &$voucherForNotify) {
                // Lock transaction row to prevent concurrent duplicate callback processing
                $tx = Transaction::where('id', $transaction->id)->lockForUpdate()->first();
                if (!$tx || $tx->status === 'success') {
                    return; // Already processed by another concurrent thread
                }

                $tx->load('plan');
                \Log::info('PROCESSING VOUCHER GENERATION (LOCKED)', ['order_id' => $orderId]);

                // Assign a voucher from stock with lockForUpdate to prevent race condition
                $voucher = Voucher::where('voucher_plan_id', $tx->voucher_plan_id)
                                 ->where('status', 'available')
                                 ->lockForUpdate()
                                 ->first();

                if (!$voucher) {
                    // Generate new voucher 6 karakter (Tanpa I, O, L agar tidak bingung)
                    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
                    do {
                        $voucherCode = substr(str_shuffle(str_repeat($chars, 6)), 0, 6);
                    } while (Voucher::where('code', $voucherCode)->exists());
                    
                    $mikrotikId = null;
                    $voucherMode = config('mikrotik.voucher_mode', env('VOUCHER_MODE', 'mikrotik'));

                    if ($voucherMode === 'mikrotik') {
                        $profileName = $tx->plan->mikrotik_profile ?: $tx->plan->name;
                        \Log::info('ATTEMPTING TO CREATE MIKROTIK USER FOR QRIS VOUCHER', [
                            'username' => $voucherCode,
                            'profile' => $profileName,
                            'order_id' => $orderId
                        ]);
                        
                        $mikrotikResult = $this->mikrotik->createUser([
                            'username' => $voucherCode,
                            'password' => '', 
                            'profile' => $profileName,
                            'limit_uptime' => $tx->plan->duration ?: '0',
                            'comment' => "Voucher QRIS: {$voucherCode} ({$tx->customer_phone})"
                        ]);
                        
                        \Log::info('MIKROTIK CREATE USER RESULT', ['result' => $mikrotikResult]);

                        // Helper untuk mengecek keberhasilan:
                        // 1. Return sukses dari MikroTik (ret ID atau .id)
                        // 2. Trap "already have user with this name" -> User SUDAH ADA di MikroTik (dianggap sukses!)
                        $checkMikrotikSuccess = function($result, &$outId) {
                            if (!is_array($result)) return false;
                            if (!isset($result['!trap']) && !isset($result['type'])) {
                                $outId = $result[0]['ret'] ?? $result[0]['.id'] ?? 'created';
                                return true;
                            }
                            $msg = strtolower($result['message'] ?? $result['!trap'] ?? '');
                            if (str_contains($msg, 'already have user') || str_contains($msg, 'already exists')) {
                                $outId = 'existing';
                                return true;
                            }
                            return false;
                        };

                        if ($checkMikrotikSuccess($mikrotikResult, $mikrotikId)) {
                            \Log::info('MikroTik user confirmed created or already exists', ['code' => $voucherCode, 'id' => $mikrotikId]);
                        } else {
                            // Retry once if first attempt failed
                            \Log::warning('MikroTik user creation first attempt failed, retrying in 300ms...', [
                                'code' => $voucherCode,
                                'result' => $mikrotikResult
                            ]);
                            usleep(300000);
                            $retryResult = $this->mikrotik->createUser([
                                'username' => $voucherCode,
                                'password' => '', 
                                'profile' => $profileName,
                                'limit_uptime' => $tx->plan->duration ?: '0',
                                'comment' => "Voucher QRIS (Retry): {$voucherCode} ({$tx->customer_phone})"
                            ]);

                            if ($checkMikrotikSuccess($retryResult, $mikrotikId)) {
                                \Log::info('MikroTik user confirmed on retry', ['code' => $voucherCode, 'id' => $mikrotikId]);
                            } else {
                                $errorDetail = is_array($retryResult) && (isset($retryResult['!trap']) || isset($retryResult['message']))
                                    ? ($retryResult['message'] ?? json_encode($retryResult))
                                    : ($retryResult === false ? 'Koneksi ke MikroTik Gagal (Timeout/Offline/Port 8728)' : json_encode($retryResult));

                                \Log::error('CRITICAL: MIKROTIK USER CREATION FAILED FOR QRIS VOUCHER', [
                                    'order_id' => $orderId,
                                    'voucher_code' => $voucherCode,
                                    'profile' => $profileName,
                                    'error' => $errorDetail
                                ]);

                                IncidentLoggerService::report(
                                    'mikrotik',
                                    'critical',
                                    'Voucher QRIS Gagal Terbit di MikroTik',
                                    "Pelanggan {$tx->customer_phone} sukses bayar QRIS Order [{$orderId}], namun voucher {$voucherCode} gagal dibuat di MikroTik Router: {$errorDetail}",
                                    [
                                        'order_id' => $orderId,
                                        'voucher_code' => $voucherCode,
                                        'phone' => $tx->customer_phone,
                                        'profile' => $profileName,
                                        'error' => $errorDetail
                                    ]
                                );
                            }
                        }
                    }

                    $voucher = Voucher::create([
                        'voucher_plan_id' => $tx->voucher_plan_id,
                        'code' => $voucherCode,
                        'price' => $tx->plan->price,
                        'status' => 'sold',
                        'customer_phone' => $tx->customer_phone,
                        'mikrotik_id' => $mikrotikId,
                        'expires_at' => null, // Voucher QRIS biasa tidak memiliki batas kedaluwarsa 3 hari
                    ]);
                    \Log::info('NEW VOUCHER GENERATED', ['code' => $voucherCode, 'mikrotik_id' => $mikrotikId]);
                } else {
                    // If using existing stock, ensure user exists in MikroTik
                    $voucherMode = config('mikrotik.voucher_mode', env('VOUCHER_MODE', 'mikrotik'));
                    if ($voucherMode === 'mikrotik') {
                        $profileName = $tx->plan->mikrotik_profile ?: $tx->plan->name;
                        $existingUser = $this->mikrotik->getHotspotUserDetailed($voucher->code);
                        if (!$existingUser) {
                            $res = $this->mikrotik->createUser([
                                'username' => $voucher->code,
                                'password' => '', 
                                'profile' => $profileName,
                                'limit_uptime' => $tx->plan->duration ?: '0',
                                'comment' => "Voucher QRIS Stock: {$voucher->code} ({$tx->customer_phone})"
                            ]);
                            $isResOk = is_array($res) && !isset($res['!trap']) && !isset($res['type']) && (isset($res[0]['ret']) || isset($res[0]['.id']) || !empty($res));
                            if ($isResOk) {
                                $voucher->mikrotik_id = $res[0]['ret'] ?? $res[0]['.id'] ?? 'created';
                            }
                        }
                    }
                    
                    $voucher->status = 'sold';
                    $voucher->customer_phone = $tx->customer_phone;
                    $voucher->expires_at = null; // Pastikan null agar voucher QRIS tidak terpengaruh batas 3 hari
                    $voucher->save();
                    \Log::info('VOUCHER FROM STOCK ASSIGNED', ['code' => $voucher->code, 'mikrotik_id' => $voucher->mikrotik_id]);
                }

                $tx->status = 'success';
                $tx->voucher_id = $voucher->id;
                $tx->save();

                $isNewlySuccessful = true;
                $voucherForNotify = $voucher;
                $transaction->voucher_id = $voucher->id;
                $transaction->status = 'success';
            });

            // Perform notifications and event logging ONLY once if this execution marked it successful
            if ($isNewlySuccessful && $voucherForNotify) {
                // WA message is NOT sent here — it is sent by the /wa-notify endpoint
                // in the WhatsApp Gateway (server.js) to avoid duplicate messages.

                // Notify WA Gateway Bot (end session if user bought via bot)
                try {
                    $waGatewayUrl = env('WHATSAPP_GATEWAY_URL', 'http://localhost:5000');
                    Http::post("{$waGatewayUrl}/wa-notify", [
                        'phone' => $transaction->customer_phone,
                        'voucher_code' => $voucherForNotify->code,
                        'plan_name' => $transaction->plan->name,
                        'amount' => (int)$transaction->amount,
                        'external_id' => $orderId,
                    ]);
                    \Log::info('WA BOT NOTIFY SENT', ['order_id' => $orderId]);
                } catch (\Exception $e) {
                    \Log::warning('WA BOT NOTIFY FAILED (non-critical)', ['error' => $e->getMessage()]);
                }

                // Phase 1: Event Analytics — automatic background tracking
                // Customer does NOT know this is happening. No notifications.
                // Idempotent: recalculates from source transactions, safe for duplicate callbacks.
                EventAnalyticsService::processTransaction($transaction);

                // ND-Point: Silent Tracking Phase — calculate and credit points
                // Customer does NOT know points are accumulating. Internal only.
                try {
                    PointService::processTransaction($transaction);
                } catch (\Throwable $pe) {
                    \Log::error('ND-Point: Payment callback point processing error (non-blocking)', [
                        'transaction_id' => $transaction->id,
                        'error' => $pe->getMessage()
                    ]);
                }
            }
        } elseif ($request->transaction_status == 'expire' || $request->transaction_status == 'cancel') {
            $transaction->status = 'expired';
            $transaction->save();
        }

        return response()->json(['message' => 'OK']);

        } catch (\Exception $e) {
            \Log::error('MIDTRANS CALLBACK UNHANDLED ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);
            return response()->json(['message' => 'Callback processed with error: ' . $e->getMessage()], 200);
        }
    }

    public function status($id)
    {
        // Security: Only return non-sensitive fields required by frontend checkout polling
        // Do not expose voucher codes, passwords, or customer phone numbers to prevent IDOR scraping
        $transaction = Transaction::select('id', 'external_id', 'status', 'amount')
            ->findOrFail($id);

        return response()->json($transaction);
    }

    public function getVoucherByOrder(Request $request)
    {
        $orderId = $request->query('order_id');
        
        $transaction = Transaction::where('external_id', $orderId)
                                    ->with(['voucher', 'plan'])
                                    ->first();

        if (!$transaction || !$transaction->voucher) {
            \Log::warning('POLLING VOUCHER NOT FOUND YET', ['order_id' => $orderId]);
            return response()->json(['message' => 'Voucher not found or payment pending'], 404);
        }

        // Self-Healing: Jika voucher belum ter-inject di MikroTik, otomatis buatkan sekarang juga!
        $voucherMode = config('mikrotik.voucher_mode', env('VOUCHER_MODE', 'mikrotik'));
        if ($voucherMode === 'mikrotik' && $transaction->voucher && in_array($transaction->voucher->status, ['sold', 'used'])) {
            try {
                if (!$transaction->voucher->mikrotik_id) {
                    $profileName = $transaction->plan?->mikrotik_profile ?: $transaction->plan?->name ?: 'default';
                    $existingUser = $this->mikrotik->getHotspotUserDetailed($transaction->voucher->code);
                    if (!$existingUser) {
                        $res = $this->mikrotik->createUser([
                            'username' => $transaction->voucher->code,
                            'password' => '',
                            'profile' => $profileName,
                            'limit_uptime' => $transaction->plan?->duration ?: '0',
                            'comment' => "Self-Heal: {$transaction->voucher->code} ({$transaction->customer_phone})"
                        ]);
                        $isResOk = is_array($res) && !isset($res['!trap']) && !isset($res['type']) && (isset($res[0]['ret']) || isset($res[0]['.id']) || !empty($res));
                        $resMsg = strtolower($res['message'] ?? $res['!trap'] ?? '');
                        if ($isResOk || str_contains($resMsg, 'already have user') || str_contains($resMsg, 'already exists')) {
                            $resId = $res[0]['ret'] ?? $res[0]['.id'] ?? 'existing';
                            $transaction->voucher->update(['mikrotik_id' => $resId]);
                            \Log::info('Voucher confirmed in MikroTik on getVoucherByOrder', ['code' => $transaction->voucher->code]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('MikroTik self-heal on getVoucherByOrder failed: ' . $e->getMessage());
            }
        }

        // Response dibuat super lengkap agar Frontend tidak error lagi
        return response()->json([
            'success' => true,
            'voucher_code' => $transaction->voucher->code, 
            'voucher_id' => $transaction->voucher->id,
            'plan_name' => $transaction->plan->name, // Nama Paket untuk display
            'amount' => (int)$transaction->amount,
            'gross_amount' => (int)$transaction->amount,
            'total' => (int)$transaction->amount,
            'data' => [
                'voucher_code' => $transaction->voucher->code,
                'code' => $transaction->voucher->code,
                'plan_name' => $transaction->plan->name,
                'amount' => (int)$transaction->amount,
            ],
            'voucher' => $transaction->voucher,
            'plan' => $transaction->plan,
            'transaction' => $transaction
        ]);
    }
}
