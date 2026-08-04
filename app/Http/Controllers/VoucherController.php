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

        try {
            // Hanya cari di Database Lokal
            $voucher = Voucher::with('plan')->where('code', $code)->first();

            if (!$voucher) {
                return response()->json(['message' => 'Voucher tidak ditemukan. Pastikan kode yang Anda masukkan sudah benar.'], 404);
            }

            // Auto-update status expired jika sudah lewat waktu
            if ($voucher->status === 'used' && $voucher->expires_at && now()->gt($voucher->expires_at)) {
                $voucher->update(['status' => 'expired']);
                $voucher->refresh();
            }

            // Hitung sisa waktu & persentase
            $timeLeft = null;
            $timePercentage = null;

            if ($voucher->status === 'used' && $voucher->used_at && $voucher->expires_at) {
                if (now()->gt($voucher->expires_at)) {
                    $timeLeft = 'Expired';
                    $timePercentage = 100;
                } else {
                    $diff = now()->diff($voucher->expires_at);
                    $parts = [];
                    if ($diff->d > 0) $parts[] = $diff->d . ' hari';
                    if ($diff->h > 0) $parts[] = $diff->h . ' jam';
                    $parts[] = $diff->i . ' menit';
                    $timeLeft = implode(', ', $parts);

                    // Hitung persentase waktu terpakai
                    $totalSeconds = $voucher->used_at->diffInSeconds($voucher->expires_at);
                    $usedSeconds = $voucher->used_at->diffInSeconds(now());
                    $timePercentage = $totalSeconds > 0 ? round(($usedSeconds / $totalSeconds) * 100, 1) : 0;
                }
            }

            $plan = $voucher->plan;

            return response()->json([
                'code' => $voucher->code,
                'status' => $voucher->status,
                'price' => $voucher->price,
                'plan_name' => $plan->name ?? 'N/A',
                'duration' => $plan->duration ?? null,
                'speed_limit' => $plan->speed_limit ?? null,
                'upload_limit' => $plan->upload_limit ?? null,
                'download_limit' => $plan->download_limit ?? null,
                'shared_users' => $plan->shared_users ?? null,
                'is_gaming' => $plan->is_gaming ?? false,
                'used_at' => $voucher->used_at ? $voucher->used_at->format('d M Y, H:i') : null,
                'expires_at' => $voucher->expires_at ? $voucher->expires_at->format('d M Y, H:i') : null,
                'time_left' => $timeLeft,
                'time_percentage' => $timePercentage,
                'mac_address' => $voucher->mac_address,
                'created_at' => $voucher->created_at ? $voucher->created_at->format('d M Y, H:i') : null,
            ]);
        } catch (\Exception $e) {
            \Log::error("Check voucher error: " . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan saat memeriksa voucher. Silakan coba lagi.'], 500);
        }
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

    public function soldVouchers(\Illuminate\Http\Request $request)
    {
        $query = Voucher::with('plan')
            ->whereIn('status', ['used', 'expired'])
            ->where('expires_at', '<=', now());

        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('expires_at', 'desc')->paginate(15);
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
