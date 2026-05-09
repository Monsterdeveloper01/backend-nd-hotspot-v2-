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
        $code = strtoupper(trim($request->query('code')));
        if (!$code) return response()->json(['message' => 'Kode voucher harus diisi'], 400);

        // 1. Cari di Database Lokal
        $voucher = Voucher::with('plan')->where('code', $code)->first();
        
        // 2. Cari data langsung ke MikroTik
        $mikrotikData = $this->mikrotik->getHotspotUserDetailed($code);

        if (!$voucher && !$mikrotikData) {
            return response()->json(['message' => 'Voucher tidak ditemukan di sistem maupun router'], 404);
        }

        // --- Kasus A: Voucher terdaftar di Database Lokal ---
        if ($voucher) {
            // Hitung sisa waktu jika sudah terpakai
            $timeLeft = null;
            if ($voucher->expires_at) {
                $diff = now()->diff($voucher->expires_at);
                if (now()->gt($voucher->expires_at)) {
                    $timeLeft = "Expired";
                } else {
                    $timeLeft = $diff->format('%d hari, %h jam, %i menit');
                }
            }

            return response()->json([
                'source' => 'database',
                'code' => $voucher->code,
                'plan_name' => $voucher->plan->name ?? 'N/A',
                'price' => $voucher->price,
                'status' => $voucher->status,
                'used_at' => $voucher->used_at ? $voucher->used_at->format('d M Y H:i') : 'Belum digunakan',
                'expires_at' => $voucher->expires_at ? $voucher->expires_at->format('d M Y H:i') : '-',
                'time_left' => $timeLeft,
                'is_online' => $mikrotikData['is_online'] ?? false,
                'mikrotik_uptime' => $mikrotikData['uptime'] ?? null,
                'mikrotik_limit' => $mikrotikData['limit_uptime'] ?? null,
            ]);
        }

        // --- Kasus B: Voucher HANYA ada di MikroTik (Manual/Legacy) ---
        return response()->json([
            'source' => 'mikrotik',
            'code' => $mikrotikData['name'],
            'plan_name' => 'MikroTik Local User (' . $mikrotikData['profile'] . ')',
            'status' => 'active_on_router',
            'uptime' => $mikrotikData['uptime'],
            'limit_uptime' => $mikrotikData['limit_uptime'],
            'is_online' => $mikrotikData['is_online'],
            'message' => 'Voucher terdeteksi langsung di Router.'
        ]);
    }

    public function index(Request $request)
    {
        $query = Voucher::with('plan')->latest();

        if ($search = $request->query('search')) {
            $query->where(function($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if ($filter = $request->query('status')) {
            if ($filter !== 'all') {
                $query->where('status', $filter);
            }
        }

        $paginated = $query->paginate(15);

        $stats = [
            'total' => Voucher::count(),
            'available' => Voucher::where('status', 'available')->count(),
            'used' => Voucher::where('status', 'used')->count(),
        ];

        return response()->json(array_merge($paginated->toArray(), ['stats' => $stats]));
    }

    /**
     * Get active vouchers and sync usage status from Mikrotik
     */
    public function activeVouchers()
    {
        try {
            // 1. Get fresh Active Users list from Mikrotik for online status
            $mikrotikActive = $this->mikrotik->getActiveUsers();
            if (!is_array($mikrotikActive)) $mikrotikActive = [];
            
            // Create a lookup map for faster checking
            $activeMap = [];
            foreach ($mikrotikActive as $session) {
                if (isset($session['user'])) {
                    $activeMap[strtolower(trim($session['user']))] = $session;
                }
            }

            // 2. Sync usage (mark as used if they just logged in, cleanup expired, etc)
            $this->syncVoucherUsage();

            // 3. Get vouchers that are currently active (status used and not expired)
            $vouchers = Voucher::with('plan')
                ->where('status', 'used')
                ->where('expires_at', '>', now())
                ->orderBy('used_at', 'desc')
                ->get();

            // 4. Map is_online status and update MAC if online
            $vouchers->each(function($v) use ($activeMap) {
                $code = strtolower(trim($v->code));
                $v->is_online = isset($activeMap[$code]);
                
                // Optionally update MAC address from live session
                if ($v->is_online && isset($activeMap[$code]['mac-address'])) {
                    $v->mac_address = $activeMap[$code]['mac-address'];
                }
            });

            return response()->json($vouchers);
        } catch (\Exception $e) {
            \Log::error("Error in activeVouchers: " . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Gagal sinkronisasi data MikroTik: ' . $e->getMessage()
            ], 500);
        }
    }

    public function soldVouchers()
    {
        // Get vouchers that have been used and have expired
        return Voucher::with('plan')
            ->whereIn('status', ['used', 'expired'])
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
            'type' => 'nullable|string|in:radius,mikrotik'
        ]);

        $plan = VoucherPlan::findOrFail($request->voucher_plan_id);
        $type = $request->type ?? 'radius'; // Default to radius as per user preference
        $vouchers = [];

        for ($i = 0; $i < $request->quantity; $i++) {
            do {
                $code = strtoupper(Str::random(6));
            } while (Voucher::where('code', $code)->exists());

            $mikrotikId = null;

            if ($type === 'mikrotik') {
                $mikrotikResult = $this->mikrotik->createUser([
                    'username' => $code,
                    'password' => '', 
                    'profile' => $plan->name,
                    'limit_uptime' => $plan->duration ?: '0'
                ]);
                $mikrotikId = $mikrotikResult[0]['.id'] ?? null;
                
                if (!$mikrotikResult) {
                    continue; // Skip if mikrotik creation fails in local mode
                }
            }

            // Create in Local DB (Radius will read from here)
            $voucher = Voucher::create([
                'voucher_plan_id' => $plan->id,
                'code' => $code,
                'price' => $plan->price,
                'status' => 'available',
                'mikrotik_id' => $mikrotikId
            ]);
            
            $vouchers[] = $voucher;
        }

        return response()->json([
            'message' => count($vouchers) . " voucher(s) generated successfully using " . strtoupper($type) . " system.",
            'vouchers' => $vouchers
        ]);
    }

    public function destroy($id)
    {
        $voucher = Voucher::findOrFail($id);
        
        // Prevent deletion if voucher is linked to transactions
        $transactionsCount = \App\Models\Transaction::where('voucher_id', $id)->count();
        if ($transactionsCount > 0) {
            return response()->json([
                'message' => "Gagal menghapus! Voucher ini memiliki {$transactionsCount} data transaksi. Anda tidak dapat menghapus voucher yang sudah terjual untuk menjaga validitas laporan keuangan."
            ], 422);
        }

        // Safe delete from Mikrotik and Local DB
        try {
            $this->mikrotik->deleteUser($voucher->code);
        } catch (\Exception $e) {
            \Log::warning("Mikrotik delete user failed for {$voucher->code}: " . $e->getMessage());
        }

        $voucher->delete();
        return response()->json(['message' => 'Voucher berhasil dihapus.']);
    }

    /**
     * Sync Mikrotik /ip/hotspot/active with Local DB
     */
    private function syncVoucherUsage()
    {
        $activeUsers = $this->mikrotik->getActiveUsers();
        
        // Pastikan activeUsers adalah array untuk mencegah error pada collect()
        if (!is_array($activeUsers)) {
            $activeUsers = [];
        }
        $activeMikrotikUsernames = collect($activeUsers)->pluck('user')->toArray();
        \App\Models\RadiusSession::where('is_active', true)
            ->whereNotIn('username', $activeMikrotikUsernames)
            ->update(['is_active' => false, 'stopped_at' => now()]);

        foreach ($activeUsers as $active) {
            $code = $active['user'] ?? null;
            if (!$code) continue;

            $voucher = Voucher::with('plan')->where('code', $code)->first();
            if ($voucher && $voucher->status !== 'used' && $voucher->plan) {
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
        // Limit cleanup to 10 vouchers per request to prevent timeouts
        $expired = Voucher::where('status', 'used')
            ->where('expires_at', '<', now())
            ->limit(10)
            ->get();

        foreach ($expired as $v) {
            try {
                $this->mikrotik->removeHotspotUser($v->code);
                $this->mikrotik->clearUserActiveSessions($v->code);
                $this->mikrotik->clearUserCookies($v->code);
            } catch (\Exception $e) {
                \Log::error("Cleanup failed for {$v->code}: " . $e->getMessage());
            }

            $v->update(['status' => 'expired']);
        }
    }

    public function kickUser($code)
    {
        $this->mikrotik->clearUserActiveSessions($code);
        return response()->json(['message' => "User $code kicked"]);
    }
}
