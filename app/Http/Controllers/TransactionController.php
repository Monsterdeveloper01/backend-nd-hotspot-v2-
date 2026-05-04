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

                \Log::info('CUSTOMER FOUND', ['name' => $customer->name, 'status' => $customer->status_bayar]);

                if ($customer->status_bayar !== 'paid') {
                    $customer->status_bayar = 'paid';
                    $customer->is_isolated = false;
                    $customer->due_date = \Carbon\Carbon::parse($customer->due_date)->addMonth();
                    $customer->save();

                    \Log::info('CUSTOMER UPDATED IN DB', ['name' => $customer->name]);

                    // Record to Transactions Table
                    Transaction::create([
                        'external_id' => $orderId,
                        'customer_phone' => $customer->whatsapp,
                        'amount' => $request->gross_amount,
                        'status' => 'success',
                        'voucher_plan_id' => null,
                    ]);

                    \Log::info('TRANSACTION RECORD CREATED');

                    // Re-enable in Mikrotik
                    $mikrotikResult = $this->mikrotik->setUserStatus($customer->name, true);
                    \Log::info('MIKROTIK SYNC RESULT', ['success' => $mikrotikResult]);

                    // Send WA Receipt
                    try {
                        $date = \Carbon\Carbon::now()->format('d/m/Y');
                        $msg = "✅ *PEMBAYARAN DITERIMA*\n\n" .
                               "Halo *{$customer->name}*,\n" .
                               "Pembayaran tagihan internet telah kami terima.\n\n" .
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
                } else {
                    \Log::warning('CUSTOMER ALREADY PAID, SKIPPING LOGIC', ['name' => $customer->name]);
                }
            }
            return response()->json(['message' => 'OK']);
        }

        // Handle Voucher Transaction
        $transaction = Transaction::where('external_id', $request->order_id)->firstOrFail();
        
        if ($request->transaction_status == 'settlement' || $request->transaction_status == 'capture') {
            if ($transaction->status !== 'success') {
                $transaction->status = 'success';

                // Assign a voucher from stock or generate one if empty
                $voucher = Voucher::where('voucher_plan_id', $transaction->voucher_plan_id)
                                 ->where('status', 'available')
                                 ->first();

                if (!$voucher) {
                    // Generate new voucher on the fly if stock is empty
                    $voucherCode = Str::upper(Str::random(8));
                    
                    $mikrotikId = null;
                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik') {
                        $mikrotikResult = $this->mikrotik->createUser([
                            'username' => $voucherCode,
                            'password' => '', 
                            'profile' => $transaction->plan->name,
                            'limit_uptime' => $transaction->plan->duration ?: '0'
                        ]);
                        $mikrotikId = $mikrotikResult[0]['.id'] ?? null;
                    }

                    $voucher = Voucher::create([
                        'voucher_plan_id' => $transaction->voucher_plan_id,
                        'code' => $voucherCode,
                        'status' => 'sold',
                        'customer_phone' => $transaction->customer_phone,
                        'mikrotik_id' => $mikrotikId
                    ]);
                } else {
                    // If using existing stock, check if we need to inject into Mikrotik
                    if (env('VOUCHER_MODE', 'radius') === 'mikrotik' && !$voucher->mikrotik_id) {
                        $mikrotikResult = $this->mikrotik->createUser([
                            'username' => $voucher->code,
                            'password' => '', 
                            'profile' => $transaction->plan->name,
                            'limit_uptime' => $transaction->plan->duration ?: '0'
                        ]);
                        $voucher->mikrotik_id = $mikrotikResult[0]['.id'] ?? null;
                    }
                    
                    $voucher->status = 'sold';
                    $voucher->customer_phone = $transaction->customer_phone;
                    $voucher->save();
                }

                $transaction->voucher_id = $voucher->id;
                
                // Send WA
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
}
