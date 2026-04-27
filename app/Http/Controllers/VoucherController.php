<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\VoucherPlan;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class VoucherController extends Controller
{
    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    public function checkVoucher(Request $request)
    {
        $code = strtoupper($request->query('code'));
        if (!$code) return response()->json(['message' => 'Kode voucher harus diisi'], 400);

        $voucher = Voucher::with('plan')->where('code', $code)->first();

        if (!$voucher) {
            return response()->json(['message' => 'Voucher tidak ditemukan'], 404);
        }

        // Sync if it's currently active in Mikrotik but not marked as used yet
        // (Just in case the background sync hasn't run yet)
        $this->syncVoucherUsage();
        $voucher->refresh();

        return response()->json($voucher);
    }

    public function index()
    {
        return Voucher::with('plan')->latest()->paginate(15);
    }

    /**
     * Get active vouchers and sync usage status from Mikrotik
     */
    public function activeVouchers()
    {
        // 1. Sync usage from Mikrotik Active list
        $this->syncVoucherUsage();

        // 2. Get used vouchers that haven't expired yet
        $vouchers = Voucher::with('plan')
            ->where('status', 'used')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->orderBy('used_at', 'desc')
            ->get();

        return response()->json($vouchers);
    }
    public function soldVouchers()
    {
        // Get vouchers that have been used and have expired
        return Voucher::with('plan')
            ->where('status', 'used')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at', 'desc')
            ->paginate(15);
    }

    public function getLogs()
    {
        // Get recent usage logs (started or expired)
        return Voucher::with('plan')
            ->whereNotNull('used_at')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();
    }

    public function generate(Request $request)
    {
        $request->validate([
            'voucher_plan_id' => 'required|exists:voucher_plans,id',
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        $plan = VoucherPlan::findOrFail($request->voucher_plan_id);
        $vouchers = [];

        for ($i = 0; $i < $request->quantity; $i++) {
            do {
                $code = strtoupper(Str::random(6));
            } while (Voucher::where('code', $code)->exists());

            $mikrotikResult = $this->mikrotik->createUser([
                'username' => $code,
                'password' => '', 
                'profile' => $plan->name,
                'limit_uptime' => $plan->duration ?: '0'
            ]);

            if ($mikrotikResult) {
                $voucher = Voucher::create([
                    'voucher_plan_id' => $plan->id,
                    'code' => $code,
                    'price' => $plan->price,
                    'status' => 'available',
                    'mikrotik_id' => $mikrotikResult[0]['.id'] ?? null
                ]);
                $vouchers[] = $voucher;
            }
        }

        return response()->json([
            'message' => count($vouchers) . ' voucher(s) generated successfully.',
            'vouchers' => $vouchers
        ]);
    }

    public function destroy($id)
    {
        $voucher = Voucher::findOrFail($id);
        $this->mikrotik->deleteUser($voucher->code);
        $voucher->delete();
        return response()->json(['message' => 'Voucher deleted successfully.']);
    }

    /**
     * Sync Mikrotik /ip/hotspot/active with Local DB
     */
    private function syncVoucherUsage()
    {
        $activeUsers = $this->mikrotik->getActiveUsers();
        
        foreach ($activeUsers as $active) {
            $code = $active['user'] ?? null;
            if (!$code) continue;

            $voucher = Voucher::where('code', $code)->first();
            if ($voucher && $voucher->status !== 'used') {
                $durationStr = $voucher->plan->duration; 
                $expiresAt = $this->calculateExpiry($durationStr);

                $voucher->update([
                    'status' => 'used',
                    'used_at' => now(),
                    'expires_at' => $expiresAt,
                    'mac_address' => $active['mac-address'] ?? null
                ]);
            }
        }

        $this->cleanupExpiredVouchers();
    }

    private function calculateExpiry($durationStr)
    {
        if (!$durationStr) return null;
        $now = now();
        if (preg_match('/(\d+)d/', $durationStr, $m)) $now->addDays((int)$m[1]);
        if (preg_match('/(\d+)h/', $durationStr, $m)) $now->addHours((int)$m[1]);
        if (preg_match('/(\d+)m/', $durationStr, $m)) $now->addMinutes((int)$m[1]);
        return $now;
    }

    private function cleanupExpiredVouchers()
    {
        $expired = Voucher::where('status', 'used')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $v) {
            $this->mikrotik->removeHotspotUser($v->code);
            $this->mikrotik->clearUserActiveSessions($v->code);
            $this->mikrotik->clearUserCookies($v->code);
        }
    }

    public function kickUser($code)
    {
        $this->mikrotik->clearUserActiveSessions($code);
        return response()->json(['message' => "User $code kicked"]);
    }
}
