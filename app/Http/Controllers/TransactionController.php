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
            
            // Get QRIS URL from actions
            $qrisUrl = '';
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
            ]);

            return response()->json([
                'success' => true,
                'transaction' => $transaction->load('plan')
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
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
            'data' => [
                'voucher_code' => $transaction->voucher->code,
                'code' => $transaction->voucher->code,
            ],
            'voucher' => $transaction->voucher,
            'plan' => $transaction->plan,
            'transaction' => $transaction
        ]);
    }
}
