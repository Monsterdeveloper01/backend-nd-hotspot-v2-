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
        $serverKey = env('MIDTRANS_SERVER_KEY');
        $hashed = hash("sha512", $request->order_id . $request->status_code . $request->gross_amount . $serverKey);

        if ($hashed !== $request->signature_key) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        // Handle Customer Billing Payment
        if (str_starts_with($request->order_id, 'BILL-')) {
            if ($request->transaction_status == 'settlement' || $request->transaction_status == 'capture') {
                $parts = explode('-', $request->order_id);
                $customerId = $parts[1];
                $customer = Customer::find($customerId);
                
                if ($customer && $customer->status_bayar !== 'paid') {
                    $customer->status_bayar = 'paid';
                    $customer->is_isolated = false;
                    $customer->due_date = Carbon::parse($customer->due_date)->addMonth();
                    $customer->save();

                    // Re-enable in Mikrotik
                    $this->mikrotik->setUserStatus($customer->name, true);

                    // Send WA Receipt
                    $this->wa->sendMessage($customer->whatsapp, "Terima kasih! Pembayaran tagihan internet sebesar Rp " . number_format($customer->billing_amount, 0, ',', '.') . " melalui portal pembayaran telah berhasil. Layanan Anda aktif hingga " . $customer->due_date->format('d M Y') . ".");
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
                $msg = "Terima kasih! Pembayaran Anda berhasil.\n\n" .
                       "Detail Voucher ND-HOTSPOT:\n" .
                       "Kode: *{$voucher->code}*\n" .
                       "Paket: {$transaction->plan->name}\n" .
                       "Durasi: {$transaction->plan->duration}\n\n" .
                       "Silakan hubungkan ke WiFi ND-HOTSPOT dan masukkan kode di atas. Selamat internetan!";
                
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
