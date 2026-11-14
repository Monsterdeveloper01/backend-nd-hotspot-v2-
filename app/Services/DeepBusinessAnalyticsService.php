<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherPlan;
use App\Models\RadiusSession;
use App\Models\RadiusClient;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeepBusinessAnalyticsService
{
    /**
     * Get complete Deep Business Analytics metrics & AI insights
     */
    public function getAnalytics(int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();
        $endDate = now()->endOfDay();

        // 1. Peak Hours & Location Traffic
        $peakHoursData = $this->calculatePeakHours($startDate, $endDate);

        // 2. Plan Profitability vs Popularity Matrix
        $plansMatrix = $this->calculatePlanMatrix($startDate, $endDate);

        // 3. Churn Rate & Customer Retention
        $retentionData = $this->calculateChurnAndRetention();

        // 4. Next Month Revenue Forecasting
        $forecasting = $this->calculateRevenueForecast();

        // 5. Sales Channels & Reseller Performance
        $channelData = $this->calculateSalesChannels($startDate, $endDate);

        // 6. High-level Summary Metrics
        $summary = $this->calculateExecutiveSummary($startDate, $endDate);

        // 7. AI Executive Insights (Hybrid: Gemini LLM if key exists, or Advanced Rule-based AI Engine)
        $aiInsights = $this->generateAiInsights([
            'summary' => $summary,
            'peak_hours' => $peakHoursData,
            'plans_matrix' => $plansMatrix,
            'retention' => $retentionData,
            'forecasting' => $forecasting,
            'channels' => $channelData,
        ]);

        return [
            'period_days' => $days,
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'peak_hours' => $peakHoursData,
            'plans_matrix' => $plansMatrix,
            'retention' => $retentionData,
            'forecasting' => $forecasting,
            'channels' => $channelData,
            'ai_insights' => $aiInsights,
        ];
    }

    /**
     * 1. Calculate Peak Hours and Traffic by Location / Router
     */
    private function calculatePeakHours(Carbon $startDate, Carbon $endDate): array
    {
        // 24-Hour Distribution from Transactions
        $txHourly = Transaction::select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('count(*) as tx_count'),
                DB::raw('sum(amount) as total_amount')
            )
            ->where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // 24-Hour Distribution from Radius Sessions (if available)
        $sessionHourly = RadiusSession::select(
                DB::raw('HOUR(started_at) as hour'),
                DB::raw('count(*) as session_count'),
                DB::raw('sum(bytes_in + bytes_out) as total_bytes')
            )
            ->whereBetween('started_at', [$startDate, $endDate])
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $hourlyBreakdown = [];
        $peakHourCandidates = [];

        for ($h = 0; $h < 24; $h++) {
            $hourLabel = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            $tx = $txHourly->get($h);
            $sess = $sessionHourly->get($h);

            $count = $tx ? (int)$tx->tx_count : 0;
            $revenue = $tx ? (float)$tx->total_amount : 0.0;
            $sessions = $sess ? (int)$sess->session_count : 0;
            $bytes = $sess ? (int)$sess->total_bytes : 0;

            $hourlyBreakdown[] = [
                'hour' => $h,
                'label' => $hourLabel,
                'transactions' => $count,
                'revenue' => $revenue,
                'active_sessions' => $sessions,
                'total_bytes_gb' => round($bytes / (1024 * 1024 * 1024), 2),
            ];

            $peakHourCandidates[$h] = $count + ($sessions * 2);
        }

        // Identify Top Peak Hours based strictly on real activity
        $totalActivity = array_sum($peakHourCandidates);
        $topHours = [];
        $peakRangeLabel = 'Belum ada data aktivitas';

        if ($totalActivity > 0) {
            $activeCandidates = array_filter($peakHourCandidates, fn($val) => $val > 0);
            arsort($activeCandidates);
            $topHours = array_slice(array_keys($activeCandidates), 0, 3);
            sort($topHours);

            if (!empty($topHours)) {
                $minH = min($topHours);
                $maxH = (max($topHours) + 1) % 24;
                $peakRangeLabel = sprintf('%02d:00 - %02d:00 WIB', $minH, $maxH);
            }
        }

        // Traffic by Location / NAS Client (100% real database records)
        $locations = [];
        $nasClients = RadiusClient::all()->keyBy('ip');

        $sessionsByNas = RadiusSession::select(
                'nas_ip',
                DB::raw('count(*) as session_count'),
                DB::raw('sum(bytes_in + bytes_out) as total_bytes')
            )
            ->whereBetween('started_at', [$startDate, $endDate])
            ->groupBy('nas_ip')
            ->get();

        if ($sessionsByNas->isNotEmpty()) {
            foreach ($sessionsByNas as $nas) {
                $client = $nasClients->get($nas->nas_ip);
                $name = $client ? $client->name : ($nas->nas_ip ?: 'Router Utama');
                $locations[] = [
                    'name' => $name,
                    'ip' => $nas->nas_ip ?: '-',
                    'sessions' => (int)$nas->session_count,
                    'traffic_gb' => round((int)$nas->total_bytes / (1024 * 1024 * 1024), 2),
                ];
            }
        } elseif ($nasClients->isNotEmpty()) {
            // Real registered Mikrotik/RADIUS routers awaiting connection logs
            foreach ($nasClients as $client) {
                $locations[] = [
                    'name' => $client->name,
                    'ip' => $client->ip,
                    'sessions' => 0,
                    'traffic_gb' => 0.0,
                ];
            }
        } else {
            // Absolutely no registered routers or sessions
            $locations = [];
        }

        return [
            'peak_range_label' => $peakRangeLabel,
            'top_hours' => $topHours,
            'hourly' => $hourlyBreakdown,
            'locations' => $locations,
        ];
    }

    /**
     * 2. Calculate Plan Matrix: Most Profitable vs Most Popular
     */
    private function calculatePlanMatrix(Carbon $startDate, Carbon $endDate): array
    {
        $plans = VoucherPlan::withCount(['vouchers as sold_count' => function ($q) use ($startDate, $endDate) {
            $q->where('status', '!=', 'active')
              ->whereBetween('created_at', [$startDate, $endDate]);
        }])->get();

        $transactions = Transaction::select(
                'voucher_plan_id',
                DB::raw('count(*) as tx_count'),
                DB::raw('sum(amount) as total_revenue')
            )
            ->where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('voucher_plan_id')
            ->get()
            ->keyBy('voucher_plan_id');

        $totalRevenue = (float)$transactions->sum('total_revenue');
        $totalVolume = (int)$transactions->sum('tx_count');

        $matrix = [];
        foreach ($plans as $plan) {
            $tx = $transactions->get($plan->id);
            $planRev = $tx ? (float)$tx->total_revenue : 0.0;
            $planVol = $tx ? (int)$tx->tx_count : 0;

            $revShare = $totalRevenue > 0 ? round(($planRev / $totalRevenue) * 100, 1) : 0;
            $volShare = $totalVolume > 0 ? round(($planVol / $totalVolume) * 100, 1) : 0;

            // Quadrant Categorization
            if ($volShare >= 20 && $revShare >= 20) {
                $category = 'Cash Cow (Tulang Punggung)';
                $badge = 'emerald';
                $icon = 'star';
            } elseif ($volShare >= 20 && $revShare < 20) {
                $category = 'Volume Driver (Laku Cepat, Margin Tipis)';
                $badge = 'blue';
                $icon = 'bolt';
            } elseif ($volShare < 20 && $revShare >= 20) {
                $category = 'Profit Booster (Margin Tinggi)';
                $badge = 'amber';
                $icon = 'gem';
            } else {
                $category = 'Niche / Perlu Evaluasi';
                $badge = 'slate';
                $icon = 'info';
            }

            $matrix[] = [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (float)$plan->price,
                'duration' => $plan->duration,
                'is_gaming' => (bool)$plan->is_gaming,
                'volume_sold' => $planVol,
                'total_revenue' => $planRev,
                'revenue_share_pct' => $revShare,
                'volume_share_pct' => $volShare,
                'classification' => $category,
                'badge' => $badge,
                'icon' => $icon,
            ];
        }

        // Sort by Revenue descending
        usort($matrix, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        // Identify Winner & Volume Champion
        $mostProfitable = !empty($matrix) ? $matrix[0] : null;
        $volumeSorted = $matrix;
        usort($volumeSorted, fn($a, $b) => $b['volume_sold'] <=> $a['volume_sold']);
        $mostPopular = !empty($volumeSorted) ? $volumeSorted[0] : null;

        return [
            'total_plans' => count($matrix),
            'grand_revenue' => $totalRevenue,
            'grand_volume' => $totalVolume,
            'most_profitable' => $mostProfitable,
            'most_popular' => $mostPopular,
            'plans' => $matrix,
        ];
    }

    /**
     * 3. Calculate Churn Rate & Customer Retention (Cohort Analysis)
     */
    private function calculateChurnAndRetention(): array
    {
        $now = now();
        $recentStart = (clone $now)->subDays(30);
        $priorStart = (clone $now)->subDays(60);
        $priorEnd = (clone $now)->subDays(30);

        // Active customers last 30 days
        $recentCustomers = Transaction::where('status', 'success')
            ->whereNotNull('customer_phone')
            ->where('customer_phone', '!=', '')
            ->where('created_at', '>=', $recentStart)
            ->distinct()
            ->pluck('customer_phone')
            ->toArray();

        // Active customers previous period (31-60 days ago)
        $priorCustomers = Transaction::where('status', 'success')
            ->whereNotNull('customer_phone')
            ->where('customer_phone', '!=', '')
            ->whereBetween('created_at', [$priorStart, $priorEnd])
            ->distinct()
            ->pluck('customer_phone')
            ->toArray();

        $recentCount = count($recentCustomers);
        $priorCount = count($priorCustomers);

        // Churned = customers who bought in prior period but NOT in recent 30 days
        $retainedFromPrior = array_intersect($priorCustomers, $recentCustomers);
        $churnedCustomers = array_diff($priorCustomers, $recentCustomers);

        $churnCount = count($churnedCustomers);
        $retainedCount = count($retainedFromPrior);

        $churnRate = $priorCount > 0 ? round(($churnCount / $priorCount) * 100, 1) : 0.0;
        $retentionRate = 100.0 - $churnRate;

        // Repeat buyer frequency analysis in the last 60 days
        $purchaseCounts = Transaction::select('customer_phone', DB::raw('count(*) as total_orders'))
            ->where('status', 'success')
            ->whereNotNull('customer_phone')
            ->where('customer_phone', '!=', '')
            ->where('created_at', '>=', $priorStart)
            ->groupBy('customer_phone')
            ->get();

        $vipCount = 0;       // 4+ orders
        $repeatCount = 0;    // 2-3 orders
        $oneTimeCount = 0;   // 1 order

        foreach ($purchaseCounts as $p) {
            $cnt = (int)$p->total_orders;
            if ($cnt >= 4) {
                $vipCount++;
            } elseif ($cnt >= 2) {
                $repeatCount++;
            } else {
                $oneTimeCount++;
            }
        }

        $totalEvaluated = count($purchaseCounts);
        $repeatRate = $totalEvaluated > 0 ? round((($vipCount + $repeatCount) / $totalEvaluated) * 100, 1) : 0.0;

        return [
            'total_active_30d' => $recentCount,
            'prior_active_30d' => $priorCount,
            'churn_count' => $churnCount,
            'retained_count' => $retainedCount,
            'churn_rate_pct' => $churnRate,
            'retention_rate_pct' => $retentionRate,
            'repeat_purchase_rate_pct' => $repeatRate,
            'segments' => [
                'vip_regulars' => $vipCount,
                'repeat_buyers' => $repeatCount,
                'one_time_buyers' => $oneTimeCount,
                'at_risk_churn' => $churnCount,
            ]
        ];
    }

    /**
     * 4. Next Month Revenue Forecasting
     */
    private function calculateRevenueForecast(): array
    {
        $now = now();
        $startDate = (clone $now)->subDays(30);

        // Daily revenue for the last 30 days
        $dailyData = Transaction::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('sum(amount) as daily_revenue')
            )
            ->where('status', 'success')
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $dailyAmounts = $dailyData->pluck('daily_revenue')->map(fn($v) => (float)$v)->toArray();
        $activeDays = count($dailyAmounts);
        $total30d = array_sum($dailyAmounts);

        if ($total30d <= 0 || $activeDays === 0) {
            return [
                'current_30d_revenue' => 0.0,
                'daily_run_rate' => 0.0,
                'growth_velocity_pct' => 0.0,
                'forecast_next_month' => [
                    'conservative' => 0.0,
                    'moderate' => 0.0,
                    'optimistic' => 0.0,
                ],
                'confidence_score' => 'Menunggu Transaksi Perdana',
                'key_growth_lever' => 'Sistem sedang memantau transaksi. Estimasi omset otomatis aktif begitu transaksi voucher pertama mulai masuk.',
            ];
        }

        $dailyRunRate = $total30d / $activeDays;

        // Velocity: Compare last 14 days vs previous 14 days
        $last14 = array_slice($dailyAmounts, -14);
        $prior14 = array_slice($dailyAmounts, -28, 14);

        $sumRecent = array_sum($last14);
        $sumPrior = array_sum($prior14);
        $growthVelocityPct = $sumPrior > 0 
            ? round((($sumRecent - $sumPrior) / $sumPrior) * 100, 1) 
            : 0.0;

        // 3 Projection Scenarios for Next 30 Days:
        // 1. Conservative (Pessimistic: 85% run rate)
        $conservative = round($dailyRunRate * 30 * 0.85);

        // 2. Moderate (Baseline with velocity momentum)
        $growthFactor = 1 + ($growthVelocityPct / 200);
        $moderate = round($dailyRunRate * 30 * max(0.9, min(1.35, $growthFactor)));

        // 3. Optimistic (Growth Target with full velocity)
        $optimistic = round($moderate * 1.18);

        return [
            'current_30d_revenue' => $total30d,
            'daily_run_rate' => round($dailyRunRate),
            'growth_velocity_pct' => $growthVelocityPct,
            'forecast_next_month' => [
                'conservative' => $conservative,
                'moderate' => $moderate,
                'optimistic' => $optimistic,
            ],
            'confidence_score' => $activeDays >= 20 ? 'Tinggi (Data 30 Hari Lengkap)' : 'Menengah (Data Terbatas)',
            'key_growth_lever' => $growthVelocityPct >= 0 
                ? 'Momentum penjualan positif. Optimalkan voucher berdurasi panjang untuk mengunci omset.'
                : 'Pertumbuhan agak melambat. Terapkan promo warkop atau event loyalti untuk memulihkan pemasukan harian.',
        ];
    }

    /**
     * 5. Sales Channels & Reseller Performance
     */
    private function calculateSalesChannels(Carbon $startDate, Carbon $endDate): array
    {
        $allTx = Transaction::where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $totalAmount = (float)$allTx->sum('amount');
        $totalCount = $allTx->count();

        // Categorize by payment_method / external_id
        $channels = [
            'online_qris' => ['name' => 'QRIS Online (Midtrans)', 'revenue' => 0.0, 'count' => 0],
            'nd_points' => ['name' => 'Tukar ND-Points', 'revenue' => 0.0, 'count' => 0],
            'reseller_offline' => ['name' => 'Mitra / Reseller / Offline', 'revenue' => 0.0, 'count' => 0],
        ];

        foreach ($allTx as $tx) {
            $amt = (float)$tx->amount;
            $method = $tx->payment_method ?? '';
            $extId = $tx->external_id ?? '';

            if (in_array($method, ['nd_points', 'nd_points_autodebit']) || str_starts_with($extId, 'PTS-')) {
                $channels['nd_points']['revenue'] += $amt;
                $channels['nd_points']['count']++;
            } elseif (in_array($method, ['manual', 'cash', 'reseller']) || str_starts_with($extId, 'RSL-')) {
                $channels['reseller_offline']['revenue'] += $amt;
                $channels['reseller_offline']['count']++;
            } else {
                $channels['online_qris']['revenue'] += $amt;
                $channels['online_qris']['count']++;
            }
        }

        // Add percentages
        $results = [];
        foreach ($channels as $key => $data) {
            $rev = $data['revenue'];
            $cnt = $data['count'];
            $results[] = [
                'key' => $key,
                'name' => $data['name'],
                'revenue' => $rev,
                'count' => $cnt,
                'revenue_share_pct' => $totalAmount > 0 ? round(($rev / $totalAmount) * 100, 1) : 0,
                'aov' => $cnt > 0 ? round($rev / $cnt) : 0,
            ];
        }

        return [
            'total_revenue' => $totalAmount,
            'total_transactions' => $totalCount,
            'channels' => $results,
        ];
    }

    /**
     * 6. Calculate Executive Summary Metrics
     */
    private function calculateExecutiveSummary(Carbon $startDate, Carbon $endDate): array
    {
        $currentRevenue = (float)Transaction::where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount');

        $priorStart = (clone $startDate)->subDays(30);
        $priorEnd = (clone $startDate);
        $priorRevenue = (float)Transaction::where('status', 'success')
            ->whereBetween('created_at', [$priorStart, $priorEnd])
            ->sum('amount');

        $growth = $priorRevenue > 0 
            ? round((($currentRevenue - $priorRevenue) / $priorRevenue) * 100, 1)
            : 0.0;

        $totalVouchers = Voucher::whereBetween('created_at', [$startDate, $endDate])->count();
        $uniqueUsers = Transaction::where('status', 'success')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('customer_phone')
            ->distinct()
            ->count('customer_phone');

        return [
            'gross_revenue' => $currentRevenue,
            'prior_revenue' => $priorRevenue,
            'growth_pct' => $growth,
            'vouchers_issued' => $totalVouchers,
            'unique_buyers' => $uniqueUsers,
            'avg_spend_per_user' => $uniqueUsers > 0 ? round($currentRevenue / $uniqueUsers) : 0,
        ];
    }

    /**
     * 7. Generate Actionable AI Business Insights
     * Uses Gemini LLM if GEMINI_API_KEY is configured in .env; otherwise runs Expert Heuristic Engine.
     */
    public function generateAiInsights(array $analyticsContext): array
    {
        $geminiKey = env('GEMINI_API_KEY') ?: env('AI_API_KEY');

        if (!empty($geminiKey)) {
            try {
                $llmResult = $this->callGeminiApi($geminiKey, $analyticsContext);
                if (!empty($llmResult)) {
                    return $llmResult;
                }
            } catch (\Throwable $e) {
                Log::warning('DeepAnalyticsService: Gemini API failed, falling back to Expert Engine: ' . $e->getMessage());
            }
        }

        // Advanced Rule-Based Heuristic AI Engine
        return $this->generateHeuristicAiInsights($analyticsContext);
    }

    /**
     * Call Google Gemini API for deep natural language business intelligence
     */
    private function callGeminiApi(string $apiKey, array $context): ?array
    {
        $prompt = "Anda adalah asisten konsultan bisnis WiFi & Hotspot (ND-Hotspot) yang berbicara langsung ke pemilik usaha (bos warkop / pengusaha RT-RW Net).
Analisis data berikut dengan BAHASA INDONESIA YANG SANGAT SANTAI, RAMAH, DAN MUDAH DIMENGERTI ORANG AWAM:
DILARANG menggunakan istilah teknis/asing seperti 'churn, cohort, run-rate, cannibalization, bandwidth shaping, QoS, LTV, AOV'.
DILARANG MENGARANG DATA ATAU MENGGUNAKAN DATA HARDCODED/DUMMY. Seluruh angka, persentase, jam sibuk, dan nama paket WAJIB 100% diambil dari data JSON yang diberikan di bawah ini.
Jika data transaksi masih 0 atau kosong, sampaikan dengan jujur dan ramah bahwa sistem sedang bersiap menunggu transaksi perdana, lalu berikan tips persiapan dan promosi awal.

Data metrik bisnis riil:\n" 
            . json_encode($context, JSON_PRETTY_PRINT)
            . "\n\nFormat output WAJIB berupa JSON murni dengan struktur berikut:
            {
                \"ai_headline\": \"Judul kondisi usaha riil\",
                \"health_score\": 85,
                \"health_status\": \"Usaha Lagi Sehat & Ramai\",
                \"executive_summary\": \"Paragraf ngobrol santai menyapa si Bos tentang kondisi bisnisnya bulan ini berdasarkan data riil\",
                \"action_items\": [
                    {
                        \"title\": \"Judul Tips Praktis\",
                        \"category\": \"Tips Harga / Ajak Pelanggan Balik / Atur WiFi Biar Gak Lemot / Target Duit / Persiapan\",
                        \"priority\": \"Penting Banget / Lumayan Penting / Santai\",
                        \"impact\": \"Kira-kira dampaknya sesuai data\",
                        \"action\": \"Langkah gampang yang bisa langsung dipraktekkan hari ini\"
                    }
                ]
            }";

        $response = Http::timeout(10)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'responseMimeType' => 'application/json'
            ]
        ]);

        if ($response->successful()) {
            $jsonText = $response->json('candidates.0.content.parts.0.text');
            $decoded = json_decode($jsonText, true);
            if (is_array($decoded) && isset($decoded['executive_summary'])) {
                $decoded['provider'] = 'Google Gemini 1.5 Flash (AI Cerdas)';
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Expert Rule-based AI Engine (Deterministic Intelligence in plain human language)
     */
    private function generateHeuristicAiInsights(array $ctx): array
    {
        $summary = $ctx['summary'] ?? [];
        $peak = $ctx['peak_hours'] ?? [];
        $matrix = $ctx['plans_matrix'] ?? [];
        $retention = $ctx['retention'] ?? [];
        $forecast = $ctx['forecasting'] ?? [];

        $vouchersCount = (int)($summary['vouchers_issued'] ?? 0);
        $grossRevenue = (float)($summary['gross_revenue'] ?? 0.0);

        // Honest handling when zero transactions exist in selected period (Zero Hardcoded Data)
        if ($vouchersCount === 0 && $grossRevenue === 0.0) {
            return [
                'provider' => 'ND-Hotspot AI Intelligence (Data Riil)',
                'ai_headline' => 'Sistem Standby: Menunggu Pembelian Voucher Pertama',
                'health_score' => 70,
                'health_status' => 'Sistem Normal (Belum Ada Transaksi)',
                'executive_summary' => 'Halo Bos! Sistem ND-Hotspot sudah aktif memantau jaringan hotspot kamu, namun belum ada transaksi voucher yang tercatat pada periode ini. Begitu pelanggan mulai membeli voucher atau login WiFi, AI akan langsung membedah jam paling ramai, paket paling laris, dan potensi omset kamu secara otomatis dari data riil.',
                'action_items' => [
                    [
                        'title' => 'Cek Halaman Portal Login Hotspot',
                        'category' => 'Persiapan Sistem',
                        'priority' => 'Penting Banget',
                        'impact' => 'Memastikan pelanggan bisa langsung login lancar',
                        'action' => 'Pastikan router Mikrotik kamu sudah tersambung dan portal login warkop muncul otomatis begitu pelanggan menghubungkan HP ke WiFi ND-Hotspot.',
                    ],
                    [
                        'title' => 'Pasang Banner / Stiker QRIS di Meja Warkop',
                        'category' => 'Ajak Pelanggan',
                        'priority' => 'Lumayan Penting',
                        'impact' => 'Mendatangkan pembeli voucher perdana',
                        'action' => 'Cetak kode QR atau tempel pengumuman di meja dan kasir agar pengunjung tahu mereka bisa langsung beli paket internet sendiri lewat HP tanpa harus antre tanya password.',
                    ],
                ],
            ];
        }

        $churn = $retention['churn_rate_pct'] ?? 0;
        $growth = $summary['growth_pct'] ?? 0;
        $peakRange = $peak['peak_range_label'] ?? null;
        $topPlan = $matrix['most_profitable']['name'] ?? null;
        $popPlan = $matrix['most_popular']['name'] ?? null;
        $moderateForecast = number_format($forecast['forecast_next_month']['moderate'] ?? 0, 0, ',', '.');
        $totalVouchers = number_format($vouchersCount, 0, ',', '.');
        $totalOmset = number_format($grossRevenue, 0, ',', '.');
        $churnCount = (int)($retention['churn_count'] ?? 0);

        // Health Score Calculation based strictly on metrics
        $score = 75;
        if ($growth > 10) $score += 10;
        elseif ($growth < -10) $score -= 10;
        if ($churn < 20) $score += 10;
        elseif ($churn > 40) $score -= 15;
        $score = max(40, min(98, $score));

        $status = $score >= 80 
            ? 'Usaha Lagi Sehat & Rame Banget!' 
            : ($score >= 65 ? 'Usaha Cukup Stabil (Bisa Digenjot Lagi)' : 'Perlu Diperhatikan (Banyak Pelanggan Kabur)');

        $actions = [];

        // 1. Saran Paket & Harga (Strictly Dynamic)
        if ($topPlan && $popPlan) {
            if ($topPlan !== $popPlan) {
                $actions[] = [
                    'title' => "Bikin Orang Beralih ke {$topPlan}",
                    'category' => 'Tips Harga & Menu',
                    'priority' => 'Penting Banget',
                    'impact' => 'Bisa naikin omset sekitar 10% - 15%',
                    'action' => "Orang paling sering beli '{$popPlan}' karena terjangkau, tapi uang terbesar kamu sebenarnya masuk dari '{$topPlan}'. Coba kasih bonus koin poin ekstra atau buat promo khusus biar orang yang terbiasa beli harian mau coba beli paket '{$topPlan}'.",
                ];
            } else {
                $actions[] = [
                    'title' => "Pertahankan Paket Juara ({$topPlan})",
                    'category' => 'Tips Harga & Menu',
                    'priority' => 'Lumayan Penting',
                    'impact' => 'Uang masuk harian tetap lancar',
                    'action' => "Paket '{$topPlan}' adalah juara umum di tempat kamu, paling laris dan paling banyak nyumbang duit. Harganya jangan diubah-ubah dulu ya, cukup pastikan koneksinya selalu lancar jaya.",
                ];
            }
        }

        // 2. Cegah Pelanggan Kabur
        if ($churnCount > 0 && $churn > 20) {
            $avgSpend = (int)($summary['avg_spend_per_user'] ?? 0);
            $potentialLoss = number_format($churnCount * $avgSpend, 0, ',', '.');
            $actions[] = [
                'title' => "Ada {$churnCount} Orang Langganan Gak Kelihatan Lagi",
                'category' => 'Ajak Pelanggan Balik',
                'priority' => 'Penting Banget',
                'impact' => 'Bisa selamatkan sekitar Rp ' . $potentialLoss . ' uang bulanan',
                'action' => "Tercatat ada {$churnCount} pelanggan yang bulan lalu aktif beli, tapi belakangan ini belum beli voucher lagi. Coba sapa mereka via WhatsApp: 'Halo kak, kangen internetan kencang di ND-Hotspot? Khusus hari ini ada bonus voucher buat kamu lho!'.",
            ];
        } else {
            $retentionRate = $retention['retention_rate_pct'] ?? 100;
            $actions[] = [
                'title' => 'Pelanggan Kamu Setia Banget (Jarang Pindah ke Lain Hati)',
                'category' => 'Bikin Tambah Betah',
                'priority' => 'Santai',
                'impact' => 'Pelanggan makin betah dan ngajak teman',
                'action' => "Tingkat kesetiaan pelanggan kamu sangat bagus ({$retentionRate}% orang selalu beli lagi). Kamu bisa ajak mereka ikut event loyalty atau promo koin biar mereka makin sering ngajak teman-temannya nongkrong.",
            ];
        }

        // 3. Jam Paling Rame & WiFi Lemot
        if ($peakRange && $peakRange !== 'Belum ada data aktivitas') {
            $actions[] = [
                'title' => "Waspada Jam Ramai: {$peakRange}",
                'category' => 'Atur WiFi Biar Gak Lemot',
                'priority' => 'Penting Banget',
                'impact' => 'Cegah pelanggan komplain WiFi lelet',
                'action' => "Di antara jam {$peakRange}, hotspot kamu lagi penuh-penuhnya orang internetan. Hindari download file berukuran besar di jam ini, dan pastikan fitur Gaming Area tetap lancar agar pengunjung game online betah.",
            ];
        }

        // 4. Tebakan Duit Masuk Bulan Depan
        if (($forecast['daily_run_rate'] ?? 0) > 0) {
            $actions[] = [
                'title' => 'Bulan Depan Kira-kira Masuk Rp ' . $moderateForecast,
                'category' => 'Target Duit',
                'priority' => 'Lumayan Penting',
                'impact' => 'Bisa tembus Rp ' . number_format($forecast['forecast_next_month']['optimistic'] ?? 0, 0, ',', '.') . ' kalau digenjot',
                'action' => "Rata-rata setiap hari masuk duit sekitar Rp " . number_format($forecast['daily_run_rate'] ?? 0, 0, ',', '.') . ". Kalau pola ini bertahan, bulan depan kamu bisa pegang omset Rp {$moderateForecast}. Mau lebih banyak? Bikin promo khusus tiap malam minggu!",
            ];
        }

        // Build dynamic executive summary narrative
        $summaryParts = ["Halo Bos! Dalam sebulan terakhir, usaha hotspot kamu sudah berhasil menjual {$totalVouchers} voucher dengan total uang masuk Rp {$totalOmset}."];
        if ($topPlan) {
            $summaryParts[] = "Penghasilan terbesar kamu disumbang oleh paket '{$topPlan}'.";
        }
        if ($peakRange && $peakRange !== 'Belum ada data aktivitas') {
            $summaryParts[] = "Jam paling ramai terpantau di {$peakRange}.";
        }
        if ($churnCount > 0) {
            $summaryParts[] = "Tercatat ada {$churnCount} orang yang belakangan ini belum kelihatan beli lagi.";
        } else {
            $summaryParts[] = "Pelanggan aktif kamu terpantau setia.";
        }
        $summaryParts[] = "Yuk simak tips praktis di bawah ini biar uang masuk makin tebal!";

        return [
            'provider' => 'ND-Hotspot Asisten Cerdas Bisnis (Data Riil)',
            'ai_headline' => "Kondisi Usaha: {$status} (Nilai: {$score}/100)",
            'health_score' => $score,
            'health_status' => $status,
            'executive_summary' => implode(' ', $summaryParts),
            'action_items' => $actions,
        ];
    }
}
