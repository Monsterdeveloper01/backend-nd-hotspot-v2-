<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\PointAccount;
use App\Models\PointRule;
use App\Models\PointTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

/**
 * PointService
 * 
 * Core Engine for ND-Point Tracking.
 * Operates SILENTLY during internal data collection phase:
 * - Customers are NOT informed and no public UI or notifications are sent.
 * - Idempotent: safe for duplicate webhooks / retry attempts.
 * - Non-blocking: failures NEVER interrupt voucher generation or payment success.
 */
class PointService
{
    /**
     * Check if point system is enabled.
     * Default: true, can be toggled via AppConfig or Admin.
     */
    public static function isSystemEnabled(): bool
    {
        try {
            $config = AppConfig::where('key', 'point_system_enabled')->value('value');
            if ($config !== null && in_array(strtolower($config), ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Process a real successful voucher transaction for point accumulation.
     * 
     * Idempotent: will NEVER award points twice for the same transaction.
     * Non-blocking: catches any internal exception and logs it.
     * 
     * @param Transaction $transaction
     * @return PointTransaction|null Created ledger record, or null if skipped/already processed
     */
    public static function processTransaction(Transaction $transaction): ?PointTransaction
    {
        try {
            // 1. Check system toggle
            if (!self::isSystemEnabled()) {
                return null;
            }

            // 2. Strict qualification criteria:
            // - status = success
            // - external_id LIKE 'ND-%'
            // - voucher_plan_id IS NOT NULL
            // - customer_phone is not empty
            if ($transaction->status !== 'success') {
                return null;
            }

            if (!str_starts_with($transaction->external_id ?? '', 'ND-')) {
                return null;
            }

            if (empty($transaction->voucher_plan_id)) {
                return null;
            }

            if (empty($transaction->customer_phone)) {
                return null;
            }

            // 3. Normalize phone number using standard system PhoneNumberService
            $normalizedPhone = PhoneNumberService::normalize($transaction->customer_phone);
            if (empty($normalizedPhone)) {
                Log::info('ND-Point: Skipped transaction with invalid phone', [
                    'transaction_id' => $transaction->id,
                    'phone' => $transaction->customer_phone,
                ]);
                return null;
            }

            // 4. Fast idempotency check before DB locking
            $existingLedger = PointTransaction::where('source_type', 'purchase')
                ->where('source_id', (string) $transaction->id)
                ->where('type', 'earn')
                ->first();

            if ($existingLedger) {
                return $existingLedger; // Already awarded, do not duplicate
            }

            // 5. Evaluate active point rules for 'purchase'
            $activeRules = PointRule::where('is_active', true)
                ->where('type', 'purchase')
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->orderBy('priority', 'asc')
                ->get();

            if ($activeRules->isEmpty()) {
                return null; // No active rule configured
            }

            $amount = (float) $transaction->amount;
            $totalPoints = 0;
            $rulesApplied = [];

            foreach ($activeRules as $rule) {
                $awarded = $rule->calculatePointsForAmount($amount, $totalPoints);
                if ($awarded > 0) {
                    $totalPoints += $awarded;
                    $rulesApplied[] = [
                        'rule_id' => $rule->id,
                        'rule_name' => $rule->name,
                        'points' => $awarded,
                    ];
                }
            }

            if ($totalPoints <= 0) {
                return null;
            }

            // 6. Execute atomic balance update & ledger creation inside DB transaction
            return DB::transaction(function () use ($transaction, $normalizedPhone, $totalPoints, $rulesApplied) {
                // Ensure Point Account exists (one per normalized phone)
                $account = PointAccount::firstOrCreate(
                    ['phone' => $normalizedPhone],
                    [
                        'balance' => 0,
                        'lifetime_earned' => 0,
                        'lifetime_spent' => 0,
                        'status' => 'active',
                    ]
                );

                // Lock account row for update to guarantee strict sequential balance integrity
                $account = PointAccount::where('id', $account->id)->lockForUpdate()->first();

                // Double check idempotency within row lock
                $alreadyProcessed = PointTransaction::where('source_type', 'purchase')
                    ->where('source_id', (string) $transaction->id)
                    ->where('type', 'earn')
                    ->first();

                if ($alreadyProcessed) {
                    return $alreadyProcessed;
                }

                $balanceBefore = (int) $account->balance;
                $balanceAfter = $balanceBefore + $totalPoints;

                // Determine readable description
                $planName = $transaction->plan->name ?? ($transaction->amount_formatted ?? ('Rp ' . number_format($transaction->amount, 0, ',', '.')));
                $description = 'Pembelian Voucher ' . $planName;

                // Create ledger entry
                $pointTx = PointTransaction::create([
                    'point_account_id' => $account->id,
                    'phone' => $normalizedPhone,
                    'type' => 'earn',
                    'source_type' => 'purchase',
                    'source_id' => (string) $transaction->id,
                    'points' => $totalPoints,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => $description,
                    'metadata' => [
                        'transaction_id' => $transaction->id,
                        'external_id' => $transaction->external_id,
                        'amount' => $transaction->amount,
                        'voucher_plan_id' => $transaction->voucher_plan_id,
                        'plan_name' => $planName,
                        'rules_applied' => $rulesApplied,
                    ],
                ]);

                // Update account balances
                $account->balance = $balanceAfter;
                $account->lifetime_earned += $totalPoints;
                $account->save();

                Log::info('ND-Point: Successfully awarded points', [
                    'phone' => $normalizedPhone,
                    'points' => $totalPoints,
                    'tx_id' => $transaction->id,
                    'new_balance' => $balanceAfter,
                ]);

                return $pointTx;
            });

        } catch (QueryException $qe) {
            // Handle unique constraint race condition gracefully (safe duplicate prevent)
            if ($qe->getCode() == 23000 || str_contains($qe->getMessage(), 'Duplicate entry')) {
                Log::info('ND-Point: Idempotency triggered via DB constraint', [
                    'transaction_id' => $transaction->id,
                ]);
                return PointTransaction::where('source_type', 'purchase')
                    ->where('source_id', (string) $transaction->id)
                    ->where('type', 'earn')
                    ->first();
            }
            Log::error('ND-Point: Database error processing points (non-blocking)', [
                'transaction_id' => $transaction->id,
                'error' => $qe->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            // CRITICAL: Point engine failure must NEVER break purchase / payment flow
            Log::error('ND-Point: Unexpected error in PointService (non-blocking)', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Manual point adjustment by Admin (Credit or Debit with audit trail).
     */
    public static function adjustPoints(string $rawPhone, int $points, string $description, ?int $adminId = null): PointTransaction
    {
        $normalizedPhone = PhoneNumberService::normalize($rawPhone);
        if (empty($normalizedPhone)) {
            throw new \InvalidArgumentException('Nomor WhatsApp pelanggan tidak valid.');
        }

        if ($points === 0) {
            throw new \InvalidArgumentException('Jumlah poin penyesuaian tidak boleh nol.');
        }

        return DB::transaction(function () use ($normalizedPhone, $points, $description, $adminId) {
            $account = PointAccount::firstOrCreate(
                ['phone' => $normalizedPhone],
                [
                    'balance' => 0,
                    'lifetime_earned' => 0,
                    'lifetime_spent' => 0,
                    'status' => 'active',
                ]
            );

            $account = PointAccount::where('id', $account->id)->lockForUpdate()->first();

            $balanceBefore = (int) $account->balance;
            $balanceAfter = $balanceBefore + $points;

            if ($balanceAfter < 0) {
                throw new \InvalidArgumentException("Saldo poin tidak mencukupi (Saldo sekarang: {$balanceBefore} poin).");
            }

            $type = 'adjustment';
            $pointTx = PointTransaction::create([
                'point_account_id' => $account->id,
                'phone' => $normalizedPhone,
                'type' => $type,
                'source_type' => 'admin',
                'source_id' => $adminId ? (string) $adminId : null,
                'points' => $points,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'metadata' => [
                    'admin_id' => $adminId,
                    'reason' => $description,
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            $account->balance = $balanceAfter;
            if ($points > 0) {
                $account->lifetime_earned += $points;
            } else {
                $account->lifetime_spent += abs($points);
            }
            $account->save();

            return $pointTx;
        });
    }

    /**
     * Reconcile / Backfill missing points from real successful transactions.
     * 
     * Idempotent & safe: only credits transactions that have NOT received points yet.
     * Never creates fake transactions, never alters transaction records.
     * 
     * @param bool $dryRun If true, only scans and returns preview without awarding points
     * @return array Reconciliation report
     */
    public static function reconcileMissing(bool $dryRun = true): array
    {
        // 1. Fetch real qualifying transactions
        $transactions = Transaction::where('status', 'success')
            ->where('external_id', 'like', 'ND-%')
            ->whereNotNull('voucher_plan_id')
            ->whereNotNull('customer_phone')
            ->with('plan')
            ->orderBy('id', 'asc')
            ->get();

        $totalScanned = $transactions->count();
        $alreadyCredited = 0;
        $missingCount = 0;
        $totalPointsAwarded = 0;
        $processedTxs = [];

        foreach ($transactions as $tx) {
            // Check if already in ledger
            $isCredited = PointTransaction::where('source_type', 'purchase')
                ->where('source_id', (string) $tx->id)
                ->where('type', 'earn')
                ->exists();

            if ($isCredited) {
                $alreadyCredited++;
                continue;
            }

            // Normalization check
            $phone = PhoneNumberService::normalize($tx->customer_phone);
            if (empty($phone)) {
                continue;
            }

            $missingCount++;

            if (!$dryRun) {
                $ledger = self::processTransaction($tx);
                if ($ledger) {
                    $totalPointsAwarded += $ledger->points;
                    $processedTxs[] = [
                        'transaction_id' => $tx->id,
                        'external_id' => $tx->external_id,
                        'phone' => $phone,
                        'points' => $ledger->points,
                    ];
                }
            } else {
                // In dry-run, calculate simulated points based on active rules
                $amount = (float) $tx->amount;
                $activeRules = PointRule::where('is_active', true)->where('type', 'purchase')->orderBy('priority', 'asc')->get();
                $pts = 0;
                foreach ($activeRules as $r) {
                    $pts += $r->calculatePointsForAmount($amount, $pts);
                }
                $totalPointsAwarded += $pts;
            }
        }

        return [
            'total_scanned' => $totalScanned,
            'already_credited' => $alreadyCredited,
            'missing_count' => $missingCount,
            'total_points_to_award' => $totalPointsAwarded,
            'dry_run' => $dryRun,
            'processed_count' => count($processedTxs),
        ];
    }

    /**
     * Compute comprehensive Point Analytics for Admin.
     */
    public static function getAnalytics(): array
    {
        $totalAccounts = PointAccount::count();
        $totalGenerated = (int) PointAccount::sum('lifetime_earned');
        $totalSpent = (int) PointAccount::sum('lifetime_spent');
        $totalBalance = (int) PointAccount::sum('balance');
        $avgPoints = $totalAccounts > 0 ? round($totalBalance / $totalAccounts, 1) : 0;
        $highestBalance = (int) (PointAccount::max('balance') ?? 0);

        // Compute median points
        $medianPoints = 0;
        if ($totalAccounts > 0) {
            $balances = PointAccount::orderBy('balance', 'asc')->pluck('balance')->toArray();
            $count = count($balances);
            $middle = (int) floor($count / 2);
            if ($count % 2 === 0) {
                $medianPoints = round(($balances[$middle - 1] + $balances[$middle]) / 2, 1);
            } else {
                $medianPoints = $balances[$middle];
            }
        }

        // Point distribution brackets
        $dist0_100 = PointAccount::whereBetween('balance', [0, 100])->count();
        $dist101_500 = PointAccount::whereBetween('balance', [101, 500])->count();
        $dist501_1000 = PointAccount::whereBetween('balance', [501, 1000])->count();
        $dist1001_3000 = PointAccount::whereBetween('balance', [1001, 3000])->count();
        $dist3000Plus = PointAccount::where('balance', '>', 3000)->count();

        $distribution = [
            ['range' => '0 – 100 poin', 'count' => $dist0_100, 'pct' => $totalAccounts > 0 ? round(($dist0_100 / $totalAccounts) * 100, 1) : 0],
            ['range' => '101 – 500 poin', 'count' => $dist101_500, 'pct' => $totalAccounts > 0 ? round(($dist101_500 / $totalAccounts) * 100, 1) : 0],
            ['range' => '501 – 1.000 poin', 'count' => $dist501_1000, 'pct' => $totalAccounts > 0 ? round(($dist501_1000 / $totalAccounts) * 100, 1) : 0],
            ['range' => '1.001 – 3.000 poin', 'count' => $dist1001_3000, 'pct' => $totalAccounts > 0 ? round(($dist1001_3000 / $totalAccounts) * 100, 1) : 0],
            ['range' => '3.000+ poin', 'count' => $dist3000Plus, 'pct' => $totalAccounts > 0 ? round(($dist3000Plus / $totalAccounts) * 100, 1) : 0],
        ];

        // Periodic earn analytics
        $todayEarned = (int) PointTransaction::where('type', 'earn')->whereDate('created_at', today())->sum('points');
        $weekEarned = (int) PointTransaction::where('type', 'earn')->where('created_at', '>=', now()->subDays(7))->sum('points');
        $monthEarned = (int) PointTransaction::where('type', 'earn')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('points');

        $activeEarnersCount = PointTransaction::where('type', 'earn')->distinct('phone')->count('phone');

        // Top Point Holders (Masked phone for general list)
        $topHolders = PointAccount::orderBy('balance', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($acc) {
                return [
                    'id' => $acc->id,
                    'phone' => $acc->phone,
                    'masked_phone' => $acc->masked_phone,
                    'balance' => $acc->balance,
                    'lifetime_earned' => $acc->lifetime_earned,
                    'lifetime_spent' => $acc->lifetime_spent,
                    'status' => $acc->status,
                    'created_at' => $acc->created_at ? $acc->created_at->format('Y-m-d H:i') : null,
                ];
            });

        // Voucher Plan Analytics: calculate points generated per voucher plan
        $planAnalytics = [];
        try {
            // Fetch real successful transactions grouped by plan
            $planStats = Transaction::where('status', 'success')
                ->where('external_id', 'like', 'ND-%')
                ->whereNotNull('voucher_plan_id')
                ->with('plan')
                ->select('voucher_plan_id', DB::raw('COUNT(*) as total_sales'), DB::raw('SUM(amount) as total_revenue'))
                ->groupBy('voucher_plan_id')
                ->get();

            foreach ($planStats as $stat) {
                $planId = $stat->voucher_plan_id;
                $planName = $stat->plan->name ?? ("Paket #{$planId}");

                // Find total points generated for this plan via point_transactions metadata
                $pointsGenerated = (int) PointTransaction::where('source_type', 'purchase')
                    ->where('type', 'earn')
                    ->where('metadata->voucher_plan_id', $planId)
                    ->sum('points');

                $planAnalytics[] = [
                    'plan_id' => $planId,
                    'plan_name' => $planName,
                    'total_sales' => (int) $stat->total_sales,
                    'total_revenue' => (float) $stat->total_revenue,
                    'points_generated' => $pointsGenerated,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('ND-Point: Voucher plan breakdown calculation warning: ' . $e->getMessage());
        }

        return [
            'summary' => [
                'total_accounts' => $totalAccounts,
                'total_points_generated' => $totalGenerated,
                'total_points_spent' => $totalSpent,
                'current_total_balance' => $totalBalance,
                'avg_points_per_customer' => $avgPoints,
                'median_points_per_customer' => $medianPoints,
                'highest_balance' => $highestBalance,
                'system_enabled' => self::isSystemEnabled(),
            ],
            'distribution' => $distribution,
            'periodic' => [
                'today_earned' => $todayEarned,
                'week_earned' => $weekEarned,
                'month_earned' => $monthEarned,
                'active_earners_count' => $activeEarnersCount,
            ],
            'top_holders' => $topHolders,
            'plan_analytics' => $planAnalytics,
        ];
    }
}
