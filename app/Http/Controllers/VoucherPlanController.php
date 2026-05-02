<?php

namespace App\Http\Controllers;

use App\Models\VoucherPlan;
use App\Services\MikrotikService;
use Illuminate\Http\Request;

class VoucherPlanController extends Controller
{
    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    public function index(Request $request)
    {
        $query = VoucherPlan::query();
        
        if ($request->has('is_gaming')) {
            $query->where('is_gaming', $request->is_gaming == 'true' || $request->is_gaming == 1);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:voucher_plans,name',
            'is_gaming' => 'boolean',
            'duration' => 'nullable|string',
            'upload_limit' => 'nullable|string',
            'download_limit' => 'nullable|string',
            'shared_users' => 'integer|min:1',
            'price' => 'integer|min:0',
        ]);

        // 1. Create in Mikrotik first
        $mikrotikResult = $this->mikrotik->createProfile($validated);

        // 2. Save to Database
        $plan = VoucherPlan::create($validated);
        $plan->update(['mikrotik_profile' => $validated['name']]);

        return response()->json([
            'message' => 'Master Voucher berhasil dibuat' . ($mikrotikResult ? ' dan sinkron ke Mikrotik' : ' (Gagal sinkron ke Mikrotik)'),
            'data' => $plan,
            'mikrotik_status' => $mikrotikResult ? 'success' : 'failed'
        ]);
    }

    public function update(Request $request, $id)
    {
        $plan = VoucherPlan::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'required|string|unique:voucher_plans,name,' . $id,
            'is_gaming' => 'boolean',
            'duration' => 'nullable|string',
            'upload_limit' => 'nullable|string',
            'download_limit' => 'nullable|string',
            'shared_users' => 'integer|min:1',
            'price' => 'integer|min:0',
        ]);

        $oldName = $plan->name;

        // 1. Sync to Mikrotik
        $mikrotikResult = $this->mikrotik->updateProfile($oldName, $validated);

        // 2. Update Database
        $plan->update($validated);
        $plan->update(['mikrotik_profile' => $validated['name']]);

        return response()->json([
            'message' => 'Master Voucher berhasil diupdate' . ($mikrotikResult ? ' dan sinkron ke Mikrotik' : ' (Gagal sinkron ke Mikrotik)'),
            'data' => $plan,
            'mikrotik_status' => $mikrotikResult ? 'success' : 'failed'
        ]);
    }

    public function destroy($id)
    {
        $plan = VoucherPlan::findOrFail($id);
        
        // 1. Prevent deletion if vouchers are using this plan
        $vouchersCount = \App\Models\Voucher::where('voucher_plan_id', $id)->count();
        if ($vouchersCount > 0) {
            return response()->json([
                'message' => "Gagal menghapus! Terdapat {$vouchersCount} data voucher yang menggunakan paket ini. Hapus data voucher terkait terlebih dahulu."
            ], 422);
        }

        // 2. Prevent deletion if transactions are using this plan
        $transactionsCount = \App\Models\Transaction::where('voucher_plan_id', $id)->count();
        if ($transactionsCount > 0) {
            return response()->json([
                'message' => "Gagal menghapus! Paket ini memiliki {$transactionsCount} data transaksi/penjualan. Untuk menjaga integritas laporan keuangan, paket yang sudah pernah terjual tidak dapat dihapus."
            ], 422);
        }

        // Remove from Mikrotik
        $this->mikrotik->deleteProfile($plan->name);
        
        $plan->delete();

        return response()->json(['message' => 'Master Voucher berhasil dihapus']);
    }
}
