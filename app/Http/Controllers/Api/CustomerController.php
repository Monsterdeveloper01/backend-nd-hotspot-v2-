<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\MikrotikService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CustomerController extends Controller
{
    protected $mikrotik;
    protected $whatsapp;

    public function __construct(MikrotikService $mikrotik, WhatsAppService $whatsapp)
    {
        $this->mikrotik = $mikrotik;
        $this->whatsapp = $whatsapp;
    }

    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->has('q')) {
            $q = $request->input('q');
            $query->where(function($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('whatsapp', 'like', "%{$q}%");
            });
        }

        if ($request->filled('is_isolated')) {
            $query->where('is_isolated', $request->boolean('is_isolated'));
        }

        if ($request->filled('status_bayar')) {
            $query->where('status_bayar', $request->input('status_bayar'));
        }

        if ($request->has('overdue')) {
            $query->where('due_date', '<', now())
                  ->where('status_bayar', '!=', 'paid');
        }

        $customers = $query->latest()->paginate(10);
        
        // Optimize: Fetch all Mikrotik users ONCE instead of in a loop
        $isMikrotikAlive = $this->mikrotik->connect();
        $mikrotikUsers = $isMikrotikAlive ? collect($this->mikrotik->getAllHotspotUsers()) : collect();

        $customers->getCollection()->transform(function ($customer) use ($mikrotikUsers) {
            // Find user in the bulk list (Case Insensitive & Trimmed)
            $dbName = strtolower(trim($customer->name));
            
            $mUser = $mikrotikUsers->first(function($val) use ($dbName) {
                return strtolower(trim($val['name'] ?? '')) === $dbName;
            });
            
            $customer->is_synced = !empty($mUser);
            $customer->mikrotik_enabled = $mUser ? ($mUser['disabled'] === 'false' || $mUser['disabled'] === false) : false;
            return $customer;
        });
        
        return response()->json($customers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:customers',
            'whatsapp' => 'required|string',
            'billing_amount' => 'required|numeric',
            'due_date' => 'required|date',
        ]);

        $customer = Customer::create($validated);
        return response()->json($customer);
    }

    public function show($id)
    {
        $customer = Customer::findOrFail($id);
        $status = $this->mikrotik->getUserStatus($customer->name);
        $customer->is_synced = $status['exists'];
        $customer->mikrotik_enabled = $status['enabled'];
        return response()->json($customer);
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);
        $oldName = $customer->name;
        
        $customer->update($request->all());

        // Sync with Mikrotik if name changed or if is_isolated changed
        try {
            if ($oldName !== $customer->name) {
                // If name changed, we might need more complex sync, 
                // but for now let's just ensure the current name exists/enabled
                $this->mikrotik->setUserStatus($customer->name, !$customer->is_isolated);
            } else if ($request->has('is_isolated')) {
                $this->mikrotik->setUserStatus($customer->name, !$customer->is_isolated);
            }
        } catch (\Exception $e) {
            \Log::warning("Update Sync: Mikrotik failed for {$customer->name}");
        }

        return response()->json($customer);
    }

    public function destroy($id)
    {
        Customer::destroy($id);
        return response()->json(['message' => 'Customer deleted']);
    }

    public function toggleStatus($id)
    {
        $customer = Customer::findOrFail($id);
        
        // Toggle the current isolation status
        $newStatus = !$customer->is_isolated;
        
        // Re-enable in Mikrotik if isolated
        $mikrotikSynced = false;
        try {
            $mikrotikSynced = $this->mikrotik->setUserStatus($customer->name, true);
        } catch (\Throwable $e) {
            // \Throwable digunakan untuk menangkap masalah struktural PHP (seperti TypeError) yang luput dari \Exception
            \Log::error("Manual Pay: Mikrotik sync failed for {$customer->name}. Info: " . $e->getMessage() . " pada baris " . $e->getLine());
        }

        $customer->is_isolated = $newStatus;
        $customer->save();

        return response()->json([
            'success' => true,
            'mikrotik_synced' => $mikrotikSynced,
            'is_isolated' => $customer->is_isolated,
            'message' => $mikrotikSynced 
                ? 'Status berhasil diperbarui dan sinkron ke MikroTik.' 
                : 'Status diperbarui di database, tapi GAGAL sinkron ke MikroTik.'
        ]);
    }

    public function payManual($id)
    {
        $customer = Customer::findOrFail($id);
        
        // Update status and move to next month
        $customer->status_bayar = 'paid';
        $customer->is_isolated = false;
        $customer->due_date = Carbon::parse($customer->due_date)->addMonth();
        $customer->save();

        // Record to Transactions Table
        $orderId = 'MANUAL-BILL-' . $customer->id . '-' . time();
        Transaction::create([
            'external_id' => $orderId,
            'customer_phone' => $customer->whatsapp,
            'amount' => $customer->billing_amount,
            'status' => 'success',
        ]);

        // Re-enable in Mikrotik if isolated
        $mikrotikSynced = false;
        try {
            $mikrotikSynced = $this->mikrotik->setUserStatus($customer->name, true);
        } catch (\Exception $e) {
            \Log::warning("Manual Pay: Mikrotik sync failed for {$customer->name}");
        }

        try {
            $date = $customer->updated_at->format('d/m/Y');
            $time = time();
            $msg = "✅ *PEMBAYARAN DITERIMA*\n\n" .
                   "Halo *{$customer->name}*,\n" .
                   "Pembayaran tagihan internet telah kami terima.\n\n" .
                   "💳 *Total:* Rp " . number_format($customer->billing_amount, 0, ',', '.') . "\n" .
                   "📅 *Tanggal:* {$date}\n" .
                   "💼 *Metode:* Cash (Manual Admin)\n" .
                   "🆔 *Order ID:* MANUAL-BILL-{$customer->id}-{$time}\n\n" .
                   "✅ *Status Layanan: AKTIF*\n" .
                   "Jika internet belum terhubung, silakan:\n" .
                   "* Logout & Login ulang\n" .
                   "* Restart Modem/ONT\n\n" .
                   "Terima kasih! 🙏\n\n" .
                   "Hormat kami,\n" .
                   "*ND-Hotspot* 💡";
            
            $this->whatsapp->sendMessage($customer->whatsapp, $msg);
        } catch (\Exception $e) {
            \Log::warning("Manual Pay: WhatsApp receipt failed for {$customer->name}");
        }

        return response()->json([
            'success' => true,
            'mikrotik_synced' => $mikrotikSynced,
            'message' => $mikrotikSynced 
                ? 'Pembayaran manual berhasil dan layanan telah diaktifkan.' 
                : 'Pembayaran berhasil, NAMUN GAGAL mengaktifkan MikroTik secara otomatis. Silakan cek status user di router.',
            'customer' => $customer
        ]);
    }

    public function searchBill(Request $request)
    {
        $query = $request->input('query');
        $customer = Customer::where('name', 'LIKE', $query)
            ->orWhere('whatsapp', 'LIKE', $query)
            ->first();

        if (!$customer) {
            // Coba pencarian yang lebih fleksibel
            $customer = Customer::where('name', 'LIKE', "%{$query}%")
                ->orWhere('whatsapp', 'LIKE', "%{$query}%")
                ->first();
        }

        if (!$customer) {
            return response()->json(['message' => 'Tagihan tidak ditemukan'], 404);
        }

        return response()->json($customer);
    }

    public function getSnapToken($id)
    {
        $customer = Customer::findOrFail($id);

        \Midtrans\Config::$serverKey = env('MIDTRANS_SERVER_KEY');
        \Midtrans\Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        \Midtrans\Config::$isSanitized = true;
        \Midtrans\Config::$is3ds = true;

        $params = [
            'transaction_details' => [
                'order_id' => 'BILL-' . $customer->id . '-' . time(),
                'gross_amount' => (int)$customer->billing_amount,
            ],
            'customer_details' => [
                'first_name' => $customer->name,
                'phone' => $customer->whatsapp,
            ],
            'item_details' => [
                [
                    'id' => 'BILL-' . $customer->id,
                    'price' => (int)$customer->billing_amount,
                    'quantity' => 1,
                    'name' => 'Tagihan Internet - ' . $customer->name,
                ]
            ]
        ];

        try {
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            return response()->json(['token' => $snapToken]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
