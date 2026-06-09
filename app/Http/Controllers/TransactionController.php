<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherPlan;
use App\Models\Customer;
use App\Services\WhatsAppService;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
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
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        if ($request->payment_type == 'qris' && in_array($request->transaction_status, ['settlement', 'capture'])) {
            
            $isSystemGenerated = Transaction::where('external_id', $orderId)->exists() || str_starts_with($orderId, 'BILL-');
            
            if (!$isSystemGenerated) {
                \Log::info('STATIC QRIS PAYMENT RECEIVED', ['order_id' => $orderId, 'amount' => $grossAmount]);
                
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
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        // Load plan relationship for name and duration
        $transaction->load('plan');
        
        if ($request->transaction_status == 'settlement' || $request->transaction_status == 'capture') {
            if ($transaction->status !== 'success') {
                $transaction->status = 'success';
                \Log::info('PROCESSING VOUCHER GENERATION', ['order_id' => $orderId]);

                // Assign a voucher from stock or generate one if empty
                $voucher = Voucher::where('voucher_plan_id', $transaction->voucher_plan_id)
                                 ->where('status', 'available')
                                 ->first();

                if (!$voucher) {
                    // Generate new voucher 6 karakter (Tanpa I, O, L agar tidak bingung)
                    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
                    $voucherCode = substr(str_shuffle(str_repeat($chars, 6)), 0, 6);
                    
                    $mikrotikId = null;
                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik') {
                        \Log::info('ATTEMPTING TO CREATE MIKROTIK USER', ['username' => $voucherCode, 'profile' => $transaction->plan->mikrotik_profile]);
                        
                        $mikrotikResult = $this->mikrotik->createUser([
                            'username' => $voucherCode,
                            'password' => '', 
                            'profile' => $transaction->plan->mikrotik_profile,
                            'limit_uptime' => $transaction->plan->duration ?: '0'
                        ]);
                        
                        \Log::info('MIKROTIK CREATE USER RESULT', ['result' => $mikrotikResult]);

                        if (is_array($mikrotikResult) && isset($mikrotikResult[0]['.id'])) {
                            $mikrotikId = $mikrotikResult[0]['.id'];
                        }
                    }

                    $voucher = Voucher::create([
                        'voucher_plan_id' => $transaction->voucher_plan_id,
                        'code' => $voucherCode,
                        'price' => $transaction->plan->price,
                        'status' => 'sold',
                        'customer_phone' => $transaction->customer_phone,
                        'mikrotik_id' => $mikrotikId
                    ]);
                    \Log::info('NEW VOUCHER GENERATED', ['code' => $voucherCode]);
                } else {
                    // If using existing stock, check if we need to inject into Mikrotik
                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik' && !$voucher->mikrotik_id) {
                        $this->mikrotik->createUser([
                            'username' => $voucher->code,
                            'password' => '', 
                            'profile' => $transaction->plan->name,
                            'limit_uptime' => $transaction->plan->duration ?: '0'
                        ]);
                    }
                    
                    $voucher->status = 'sold';
                    $voucher->customer_phone = $transaction->customer_phone;
                    $voucher->save();
                    \Log::info('VOUCHER FROM STOCK ASSIGNED', ['code' => $voucher->code]);
                }

                $transaction->voucher_id = $voucher->id;
                
                // Send WA
                try {
                    $msg = "🎫 *VOUCHER INTERNET – ND-Hotspot*\n" .
                           env('APP_URL') . "\n\n" .
                           "Halo Pelanggan,\n" .
                           "Paket: *{$transaction->plan->name}*\n" .
                           "Harga: Rp " . number_format($transaction->amount, 0, ',', '.') . "\n" .
                           "Kode: *{$voucher->code}*\n\n" .
                           "Cara Login:\n" .
                           "* Pastikan sinyal Wifi *ND-Hotspot* tercover\n" .
                           "* Pilih sinyal Wifi *ND-Hotspot*\n" .
                           "* Masukkan kode Voucher\n\n" .
                           "Hormat kami,\n" .
                           "*ND-Hotspot* 💡";
                    
                    $this->wa->sendMessage($transaction->customer_phone, $msg);
                    \Log::info('VOUCHER WA SENT');
                } catch (\Exception $e) {
                    \Log::error('VOUCHER WA FAILED', ['error' => $e->getMessage()]);
                }
                
                $transaction->save();
            }
        } elseif ($request->transaction_status == 'expire' || $request->transaction_status == 'cancel') {
            $transaction->status = 'expired';
            $transaction->save();
        }

        return response()->json(['message' => 'OK']);
    }

    public function status($id)
    {
        $transaction = Transaction::with(['voucher', 'plan'])->findOrFail($id);
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
