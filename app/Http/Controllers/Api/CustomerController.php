<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
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

    public function index()
    {
        $customers = Customer::paginate(10);
        
        $customers->getCollection()->transform(function ($customer) {
            // Check sync status with Mikrotik (limited to paginated results)
            $customer->is_synced = $this->mikrotik->checkUserExists($customer->name);
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
        $customer->is_synced = $this->mikrotik->checkUserExists($customer->name);
        return response()->json($customer);
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);
        $customer->update($request->all());
        return response()->json($customer);
    }

    public function destroy($id)
    {
        Customer::destroy($id);
        return response()->json(['message' => 'Customer deleted']);
    }

    public function payManual($id)
    {
        $customer = Customer::findOrFail($id);
        
        // Update status and move to next month
        $customer->status_bayar = 'paid';
        $customer->is_isolated = false;
        $customer->due_date = Carbon::parse($customer->due_date)->addMonth();
        $customer->save();

        // Re-enable in Mikrotik if isolated
        $this->mikrotik->setUserStatus($customer->name, true);

        // Send Receipt via WA
        $this->whatsapp->sendMessage($customer->whatsapp, "Terima kasih! Pembayaran tagihan internet sebesar Rp " . number_format($customer->billing_amount, 0, ',', '.') . " telah diterima. Layanan Anda aktif hingga " . $customer->due_date->format('d M Y') . ".");

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran manual berhasil',
            'customer' => $customer
        ]);
    }

    public function searchBill(Request $request)
    {
        $query = $request->input('query');
        $customer = Customer::where('name', $query)
            ->orWhere('whatsapp', $query)
            ->first();

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
