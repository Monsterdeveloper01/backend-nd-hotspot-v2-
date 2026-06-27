<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Voucher;
use App\Models\Customer;
use App\Models\VoucherPlan;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Transaction; // Assuming this model exists for payments

use App\Services\MikrotikService;

class DashboardController extends Controller
{
    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    public function index()
    {
        // 1. Stats
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        // Revenue
        $monthlyRevenue = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        $todayRevenue = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $today)
            ->sum('amount');

        $billRevenueToday = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $today)
            ->where(function($q) {
                $q->where('external_id', 'like', 'BILL-%')
                  ->orWhere('external_id', 'like', 'MANUAL-%');
            })
            ->sum('amount');

        $voucherRevenueToday = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $today)
            ->where('external_id', 'like', 'ND-%')
            ->sum('amount');

        // Customers
        $totalCustomers = Customer::count();
        $dueCustomers = Customer::where('due_date', '<', Carbon::now())->count();
        $isolatedCustomers = Customer::where('status_bayar', 'unpaid')->where('due_date', '<', Carbon::now()->subDays(3))->count();

        // Voucher Terjual (Berdasarkan jumlah transaksi sukses hari ini)
        $voucherSoldToday = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $today)
            ->where('external_id', 'like', 'ND-%')
            ->count();
            
        // Network Health (ONUs)
        $olts = \App\Models\OltConfig::all();
        $oltStats = [];
        $offlineOnus = 0;
        $totalOnus = 0;
        
        foreach ($olts as $olt) {
            $online = \App\Models\OnuNode::where('olt_id', $olt->id)->where('status', 'online')->count();
            $offline = \App\Models\OnuNode::where('olt_id', $olt->id)->where('status', 'offline')->count();
            
            $offlineOnus += $offline;
            $totalOnus += ($online + $offline);
            
            $oltStats[] = [
                'id' => $olt->id,
                'name' => $olt->name,
                'online' => $online,
                'offline' => $offline
            ];
        }

        // 2. Chart Data (Daily revenue this month)
        $chartData = DB::table('transactions')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
            ->where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->groupBy('date')
            ->get();

        // Voucher Chart (Last 30 Days)
        $last30Days = Carbon::today()->subDays(29);
        $voucherChartData = DB::table('transactions')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
            ->where('status', 'success')
            ->where('created_at', '>=', $last30Days)
            ->where('external_id', 'like', 'ND-%')
            ->groupBy('date')
            ->get();

        // 3. Online Users (Direct from Mikrotik for non-RADIUS)
        $mikrotikActive = [];
        try {
            // Berikan timeout atau pengecekan cepat agar tidak bikin error 500 jika router offline
            $mikrotikActive = $this->mikrotik->getActiveUsers() ?: [];
        } catch (\Exception $e) {
            \Log::warning("Dashboard: Gagal mengambil data aktif dari MikroTik: " . $e->getMessage());
        }

        $activeUsernames = array_column($mikrotikActive, 'user') ?: [];
        $lowerActiveUsernames = array_map('strtolower', $activeUsernames);

        $onlineVouchers = collect();
        if (!empty($lowerActiveUsernames)) {
            $onlineVouchers = Voucher::with('plan')
                ->whereIn('status', ['sold', 'used'])
                ->whereIn(DB::raw('LOWER(code)'), $lowerActiveUsernames)
                ->get()
                ->map(function($v) use ($mikrotikActive) {
                    $mUser = collect($mikrotikActive)->first(function($val) use ($v) {
                        return strtolower($val['user'] ?? '') === strtolower($v->code);
                    });
                    return [
                        'id' => $v->id,
                        'code' => $v->code,
                        'plan_name' => $v->plan->name ?? 'Legacy',
                        'is_online' => true,
                        'mac_address' => $mUser['mac-address'] ?? ($mUser['address'] ?? $v->mac_address),
                        'uptime' => $mUser['uptime'] ?? '-',
                        'bytes_in' => $mUser['bytes-in'] ?? '0',
                        'bytes_out' => $mUser['bytes-out'] ?? '0',
                        'used_at' => $v->used_at,
                        'expires_at' => $v->expires_at,
                    ];
                });
        }

        $offlineVouchers = Voucher::with('plan')
            ->whereIn('status', ['sold', 'used'])
            ->whereNotIn(DB::raw('LOWER(code)'), $lowerActiveUsernames)
            ->orderBy('updated_at', 'desc')
            ->take(10)
            ->get()
            ->map(function($v) {
                return [
                    'id' => $v->id,
                    'code' => $v->code,
                    'plan_name' => $v->plan->name ?? 'Legacy',
                    'is_online' => false,
                    'mac_address' => $v->mac_address,
                    'uptime' => '-',
                    'used_at' => $v->used_at,
                    'expires_at' => $v->expires_at,
                ];
            });

        $combinedUsers = $onlineVouchers->concat($offlineVouchers)->take(15);

        // 4. Recent Transactions
        $recentTransactions = Transaction::with(['plan', 'voucher', 'customer'])
            ->where('status', 'success')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'stats' => [
                'monthly_revenue' => $monthlyRevenue,
                'today_revenue' => $todayRevenue,
                'bill_revenue_today' => $billRevenueToday,
                'voucher_revenue_today' => $voucherRevenueToday,
                'total_customers' => $totalCustomers,
                'due_customers' => $dueCustomers,
                'isolated_customers' => $isolatedCustomers,
                'voucher_sold_today' => $voucherSoldToday,
                'online_count' => count($onlineVouchers),
                'offline_onus' => $offlineOnus,
                'total_onus' => $totalOnus,
                'olt_stats' => $oltStats,
            ],
            'chart' => $chartData,
            'voucher_chart' => $voucherChartData,
            'combined_users' => $combinedUsers,
            'recent_transactions' => $recentTransactions
        ]);
    }

    public function refreshMikrotik()
    {
        // Clear connection cache
        \Illuminate\Support\Facades\Cache::forget('mikrotik_connected');
        $connected = $this->mikrotik->connect();
        
        return response()->json([
            'success' => $connected,
            'message' => $connected ? 'Synchronized with Mikrotik' : 'Failed to connect to Router'
        ]);
    }

    public function transactions(Request $request)
    {
        $query = Transaction::with(['plan', 'voucher', 'customer'])
            ->where('status', 'success')
            ->orderBy('created_at', 'desc');

        if ($request->filter === 'bill') {
            $query->where('external_id', 'like', 'BILL-%');
        } elseif ($request->filter === 'voucher') {
            $query->where('external_id', 'like', 'ND-%');
        }

        return $query->paginate(10);
    }
}
