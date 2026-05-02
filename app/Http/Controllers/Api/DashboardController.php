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
            ->where('external_id', 'like', 'BILL-%')
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

        // Vouchers Sold
        $voucherSoldToday = Voucher::where('status', 'sold')
            ->where('updated_at', '>=', $today)
            ->count();

        // 2. Chart Data (Daily revenue this month)
        $chartData = DB::table('transactions')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
            ->where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->groupBy('date')
            ->get();

        // 3. Online Users (Combined data from Mikrotik and DB)
        $mikrotikActive = $this->mikrotik->getActiveUsers();
        $activeUsernames = array_column($mikrotikActive, 'user');

        $onlineVouchers = Voucher::with('plan')
            ->where('status', 'sold')
            ->whereIn('code', $activeUsernames)
            ->get()
            ->map(function($v) use ($mikrotikActive) {
                $mUser = collect($mikrotikActive)->firstWhere('user', $v->code);
                return [
                    'id' => $v->id,
                    'code' => $v->code,
                    'plan_name' => $v->plan->name,
                    'is_online' => true,
                    'mac_address' => $mUser['address'] ?? $v->mac_address,
                    'uptime' => $mUser['uptime'] ?? '-',
                    'bytes_in' => $mUser['bytes-in'] ?? '0',
                    'bytes_out' => $mUser['bytes-out'] ?? '0',
                    'used_at' => $v->used_at,
                    'expires_at' => $v->expires_at,
                ];
            });

        $offlineVouchers = Voucher::with('plan')
            ->where('status', 'sold')
            ->whereNotIn('code', $activeUsernames)
            ->orderBy('updated_at', 'desc')
            ->take(10)
            ->get()
            ->map(function($v) {
                return [
                    'id' => $v->id,
                    'code' => $v->code,
                    'plan_name' => $v->plan->name,
                    'is_online' => false,
                    'mac_address' => $v->mac_address,
                    'uptime' => '-',
                    'used_at' => $v->used_at,
                    'expires_at' => $v->expires_at,
                ];
            });

        $combinedUsers = $onlineVouchers->concat($offlineVouchers)->take(15);

        // 4. Recent Transactions
        $recentTransactions = Transaction::with(['plan', 'voucher'])
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
            ],
            'chart' => $chartData,
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
        $query = Transaction::with(['plan', 'voucher'])
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
