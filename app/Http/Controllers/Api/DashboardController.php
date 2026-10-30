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

        $voucherRevenueMonth = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->where('external_id', 'like', 'ND-%')
            ->sum('amount');

        $billRevenueMonth = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->where('external_id', 'like', 'BILL-%')
            ->sum('amount');

        $manualBillRevenueMonth = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->where('external_id', 'like', 'MANUAL-%')
            ->sum('amount');

        $qrisStatisRevenueMonth = DB::table('transactions')
            ->where('status', 'success')
            ->where('created_at', '>=', $startOfMonth)
            ->where('payment_method', 'qris_statis')
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

        // Bill Chart (Gabungan BILL dan MANUAL BILL)
        $billChartData = DB::table('transactions')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
            ->where('status', 'success')
            ->where('created_at', '>=', $last30Days)
            ->where(function($query) {
                $query->where('external_id', 'like', 'BILL-%')
                      ->orWhere('external_id', 'like', 'MANUAL-%');
            })
            ->groupBy('date')
            ->get();

        // QRIS Statis Chart
        $qrisStatisChartData = DB::table('transactions')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
            ->where('status', 'success')
            ->where('created_at', '>=', $last30Days)
            ->where('payment_method', 'qris_statis')
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
            ->take(30)
            ->get();

        // 5. Auto-Debit Subscribers (ND-Points)
        $autoDebitAccounts = \App\Models\PointAccount::with('autoDebitPlan')
            ->where('auto_debit_enabled', true)
            ->orderBy('updated_at', 'desc')
            ->get();

        $autoDebitUsers = $autoDebitAccounts->map(function ($acc) {
            $trigger = $acc->auto_debit_trigger_type ?: 'on_expiry';
            $scheduleDesc = $trigger === 'monthly_date'
                ? 'Setiap tgl ' . ($acc->auto_debit_day ?: 1)
                : 'Setelah voucher habis';

            return [
                'id' => $acc->id,
                'phone' => $acc->phone,
                'masked_phone' => $acc->masked_phone,
                'balance' => (int) $acc->balance,
                'plan_id' => $acc->auto_debit_plan_id,
                'plan_name' => $acc->autoDebitPlan?->name ?? 'Paket Hotspot',
                'plan_price' => (int) ($acc->autoDebitPlan?->price ?? 0),
                'plan_duration' => $acc->autoDebitPlan?->duration ?? '-',
                'trigger_type' => $trigger,
                'day' => $acc->auto_debit_day,
                'schedule_desc' => $scheduleDesc,
                'fail_count' => (int) $acc->auto_debit_fail_count,
                'last_run_at' => $acc->auto_debit_last_run_at ? $acc->auto_debit_last_run_at->format('d/m/Y H:i') : null,
                'updated_at' => $acc->updated_at ? $acc->updated_at->format('d/m/Y H:i') : null,
            ];
        });

        return response()->json([
            'stats' => [
                'monthly_revenue' => $monthlyRevenue,
                'voucher_revenue_month' => $voucherRevenueMonth,
                'bill_revenue_month' => $billRevenueMonth,
                'manual_bill_revenue_month' => $manualBillRevenueMonth,
                'qris_statis_revenue_month' => $qrisStatisRevenueMonth,
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
                'auto_debit_count' => $autoDebitUsers->count(),
                'auto_debit_users' => $autoDebitUsers,
            ],
            'chart' => $chartData,
            'voucher_chart' => $voucherChartData,
            'bill_chart' => $billChartData,
            'qris_statis_chart' => $qrisStatisChartData,
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
            $query->where(function($q) {
                $q->where('external_id', 'like', 'BILL-%')
                  ->orWhere('external_id', 'like', 'MANUAL-%');
            });
        } elseif ($request->filter === 'voucher') {
            $query->where(function($q) {
                $q->where('external_id', 'like', 'ND-%')
                  ->orWhere(function($sub) {
                      $sub->whereNotNull('voucher_plan_id')
                          ->where('external_id', 'not like', 'PTS-%')
                          ->where('payment_type', '!=', 'nd_points')
                          ->where('payment_type', '!=', 'nd_points_autodebit');
                  });
            })->where('payment_method', '!=', 'qris_statis')
              ->where('external_id', 'not like', 'QRIS-%')
              ->where('external_id', 'not like', 'PTS-%');
        } elseif ($request->filter === 'point_topup') {
            $query->where(function($q) {
                $q->where('external_id', 'like', 'PTS-TOPUP-%')
                  ->orWhere('payment_type', 'point_topup')
                  ->orWhere(function($sub) {
                      $sub->where('external_id', 'like', 'PTS-%')
                          ->where('external_id', 'not like', 'PTS-REDEEM-%')
                          ->where('external_id', 'not like', 'PTS-VOUCHER-%')
                          ->where('external_id', 'not like', 'PTS-AUTODEBIT-%');
                  });
            });
        } elseif ($request->filter === 'point_redeem') {
            $query->where(function($q) {
                $q->where('external_id', 'like', 'PTS-REDEEM-%')
                  ->orWhere('external_id', 'like', 'PTS-VOUCHER-%')
                  ->orWhere('external_id', 'like', 'PTS-AUTODEBIT-%')
                  ->orWhere('payment_type', 'nd_points')
                  ->orWhere('payment_type', 'nd_points_autodebit');
            });
        } elseif ($request->filter === 'qris') {
            $query->where(function($q) {
                $q->where('payment_method', 'qris_statis')
                  ->orWhere('external_id', 'like', 'QRIS-%');
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function($q) use ($search) {
                $q->where('external_id', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhereHas('customer', function($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('whatsapp', 'like', "%{$search}%");
                  })
                  ->orWhereHas('voucher', function($vq) use ($search) {
                      $vq->where('code', 'like', "%{$search}%");
                  });
            });
        }

        return $query->paginate(15);
    }
}
