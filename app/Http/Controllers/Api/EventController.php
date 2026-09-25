<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Transaction;
use App\Models\EventReward;
use App\Models\EventRewardRule;
use App\Models\VoucherPlan;
use App\Services\PhoneNumberService;
use App\Services\RewardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * EventController
 * 
 * Phase 1: Real-Time Event & Loyalty System (Permanent / Never Expires)
 * Internal admin-only controller for managing permanent events,
 * 100% real-time transaction tracking, and customer loyalty analytics.
 * 
 * Sisi customer: BELUM menerima hadiah / voucher gratis (Admin internal only).
 */
class EventController extends Controller
{
    /**
     * List all events.
     * GET /admin/events
     */
    public function index()
    {
        $events = Event::withCount('participants')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($event) {
                // Count unique phones across all periods
                $uniqueCustomers = EventParticipant::where('event_id', $event->id)
                    ->distinct('phone')
                    ->count('phone');

                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'description' => $event->description,
                    'target_amount' => $event->target_amount,
                    'status' => $event->status,
                    'last_synced_at' => $event->last_synced_at?->toIso8601String(),
                    'sync_stats' => $event->sync_stats,
                    'participants_count' => $event->participants_count,
                    'unique_customers' => $uniqueCustomers,
                    'created_at' => $event->created_at?->toIso8601String(),
                ];
            });

        return response()->json($events);
    }

    /**
     * Create a new permanent event.
     * POST /admin/events
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'target_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event = Event::create([
            'name' => $request->name,
            'description' => $request->description,
            'target_amount' => $request->target_amount ?? 0,
            'status' => $request->status ?? 'active',
        ]);

        // If created as active, immediately populate data for current month in real time
        if ($event->status === 'active') {
            try {
                $this->executeSync($event, Carbon::now()->startOfMonth());
            } catch (\Exception $e) {
                Log::warning('Auto-sync on event creation failed: ' . $e->getMessage());
            }
        }

        return response()->json($event, 201);
    }

    /**
     * Update an existing permanent event.
     * PUT /admin/events/{id}
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'target_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $wasInactive = $event->status === 'inactive';

        $event->update($request->only([
            'name', 'description', 'target_amount', 'status'
        ]));

        // If transitioned from inactive to active, ensure current month data is synced
        if ($wasInactive && $event->status === 'active') {
            try {
                $this->executeSync($event, Carbon::now()->startOfMonth());
            } catch (\Exception $e) {
                Log::warning('Auto-sync on event activation failed: ' . $e->getMessage());
            }
        }

        return response()->json($event);
    }

    /**
     * Delete an event (inactive only).
     * DELETE /admin/events/{id}
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        if ($event->status === 'active') {
            return response()->json([
                'message' => 'Nonaktifkan event terlebih dahulu sebelum menghapus.'
            ], 422);
        }

        $event->delete();

        return response()->json(['message' => 'Event berhasil dihapus.']);
    }

    /**
     * Sync / Rebuild transaction data into event_participants.
     * POST /admin/events/{id}/sync
     * 
     * IDEMPOTENT: Rebuilds from source transactions.
     * Default reads from event created_at forward.
     * Preserves earlier historical months unless 'all_history' or earlier 'start_date' is passed.
     */
    public function sync(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        if ($request->filled('start_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
        } elseif ($request->boolean('all_history')) {
            $startDate = Carbon::create(2020, 1, 1);
        } else {
            $startDate = $event->created_at ? $event->created_at->copy()->startOfDay() : Carbon::now()->startOfMonth();
        }

        try {
            $stats = $this->executeSync($event, $startDate);

            return response()->json([
                'success' => true,
                'message' => 'Sync realtime data berhasil.',
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error("EventSync failed", [
                'event_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync gagal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get real-time analytics for an event.
     * GET /admin/events/{id}/analytics
     */
    public function analytics(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        // If event is active and has no participants yet, auto-sync current month so real data appears instantly
        if ($event->status === 'active' && $event->participants()->count() === 0) {
            try {
                $this->executeSync($event, Carbon::now()->startOfMonth());
            } catch (\Exception $e) {
                Log::warning('Auto-sync on initial analytics view failed: ' . $e->getMessage());
            }
        }

        // Available periods sorted descending (latest month first)
        $periods = EventParticipant::where('event_id', $event->id)
            ->distinct('period_key')
            ->orderBy('period_key', 'desc')
            ->pluck('period_key');

        $participantsQuery = EventParticipant::where('event_id', $event->id);

        // Filter by period
        if ($request->filled('period')) {
            $participantsQuery->where('period_key', $request->period);
        }

        $allParticipants = $participantsQuery->get();

        // === SUMMARY METRICS ===
        $totalUniqueCustomers = EventParticipant::where('event_id', $event->id)
            ->when($request->filled('period'), fn($q) => $q->where('period_key', $request->period))
            ->distinct('phone')
            ->count('phone');

        $totalTransactions = $allParticipants->sum('transaction_count');
        $totalRevenue = (float) $allParticipants->sum('total_purchase');

        // Average purchase per customer-month (each row = one customer-month)
        $avgPurchasePerCustomerMonth = $allParticipants->count() > 0
            ? round($totalRevenue / $allParticipants->count(), 2)
            : 0;

        // Median purchase per customer-month
        $sorted = $allParticipants->pluck('total_purchase')->sort()->values();
        $medianPurchase = 0;
        if ($sorted->count() > 0) {
            $mid = floor($sorted->count() / 2);
            $medianPurchase = $sorted->count() % 2 === 0
                ? round(($sorted[$mid - 1] + $sorted[$mid]) / 2, 2)
                : round($sorted[$mid], 2);
        }

        // Highest monthly purchase (single customer-month record)
        $highestMonthlyPurchase = $allParticipants->max('total_purchase') ?? 0;

        // === DISTRIBUTION ===
        $distribution = $this->calculateDistribution($allParticipants);

        // === TARGET STATS (using event's target_amount) ===
        $targetAmount = (float) $event->target_amount;
        $targetQualifying = 0;
        $targetPercentage = 0;
        if ($targetAmount > 0) {
            $targetQualifying = $allParticipants->where('total_purchase', '>=', $targetAmount)->count();
            $targetPercentage = $totalUniqueCustomers > 0
                ? round(($targetQualifying / $totalUniqueCustomers) * 100, 1)
                : 0;
        }

        // === PARTICIPANTS TABLE (with masking & pagination) ===
        $participantsTableQuery = EventParticipant::where('event_id', $event->id)
            ->when($request->filled('period'), fn($q) => $q->where('period_key', $request->period))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim($request->search);
                $normalizedSearch = PhoneNumberService::normalize($search);
                if ($normalizedSearch) {
                    $q->where('phone', 'like', "%{$normalizedSearch}%");
                } else {
                    $digits = preg_replace('/[^0-9]/', '', $search);
                    if ($digits !== '') {
                        $q->where('phone', 'like', "%{$digits}%");
                    }
                }
            })
            ->orderByDesc('total_purchase');

        $paginatedParticipants = $participantsTableQuery->paginate(20);

        // Preload rewards for participants on the current page (Phase 2: Automatic Reward System)
        $phones = $paginatedParticipants->getCollection()->pluck('phone')->all();
        $periodKeys = $paginatedParticipants->getCollection()->pluck('period_key')->all();

        $rewards = EventReward::where('event_id', $event->id)
            ->whereIn('phone', $phones)
            ->whereIn('period_key', $periodKeys)
            ->with(['rule', 'voucher'])
            ->get()
            ->keyBy(fn($r) => "{$r->phone}_{$r->period_key}");

        // Include target achievement status, unmasked phone, and reward in participant rows
        $paginatedParticipants->getCollection()->transform(function ($p) use ($targetAmount, $rewards) {
            $key = "{$p->phone}_{$p->period_key}";
            $reward = $rewards->get($key);

            $p->avg_per_transaction = $p->transaction_count > 0
                ? round($p->total_purchase / $p->transaction_count, 2)
                : 0;
            $p->target_amount = $targetAmount;
            $p->is_target_achieved = $targetAmount > 0 && (float) $p->total_purchase >= $targetAmount;
            $p->reward = $reward ? [
                'id' => $reward->id,
                'status' => $reward->status,
                'rule_name' => $reward->rule?->name,
                'voucher_code' => $reward->voucher?->code,
                'issued_at' => $reward->issued_at?->format('d M Y, H:i'),
                'expires_at' => $reward->expires_at?->format('d M Y, H:i'),
                'error_message' => $reward->error_message,
            ] : null;
            return $p;
        });

        // Rewards summary
        $totalRewardsIssued = EventReward::where('event_id', $event->id)
            ->where('status', 'issued')
            ->count();
        $totalRewardsProcessing = EventReward::where('event_id', $event->id)
            ->where('status', 'processing')
            ->count();
        $totalRewardsFailed = EventReward::where('event_id', $event->id)
            ->where('status', 'failed')
            ->count();

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'target_amount' => $event->target_amount,
                'status' => $event->status,
                'last_synced_at' => $event->last_synced_at?->toIso8601String(),
                'sync_stats' => $event->sync_stats,
                'created_at' => $event->created_at?->toIso8601String(),
            ],
            'selected_period' => $request->period ?? null,
            'summary' => [
                'total_unique_customers' => $totalUniqueCustomers,
                'total_transactions' => $totalTransactions,
                'total_revenue' => round($totalRevenue, 2),
                'avg_purchase_per_customer_month' => $avgPurchasePerCustomerMonth,
                'median_purchase_per_customer_month' => $medianPurchase,
                'highest_monthly_purchase' => round($highestMonthlyPurchase, 2),
            ],
            'target_achievement' => [
                'target_amount' => $targetAmount,
                'qualifying_customers' => $targetQualifying,
                'total_customers' => $totalUniqueCustomers,
                'percentage' => $targetPercentage,
            ],
            'reward_rules' => $event->rewardRules()->with('voucherPlan')->get(),
            'reward_stats' => [
                'issued' => $totalRewardsIssued,
                'processing' => $totalRewardsProcessing,
                'failed' => $totalRewardsFailed,
            ],
            'test_mode' => [
                'enabled' => env('LOYALTY_TEST_MODE', false) === true || env('LOYALTY_TEST_MODE', false) === 'true',
                'whatsapp_enabled' => env('LOYALTY_TEST_WHATSAPP', false) === true || env('LOYALTY_TEST_WHATSAPP', false) === 'true',
                'active_test_states_count' => \App\Models\EventTestState::where('event_id', $event->id)->count(),
            ],
            'distribution' => $distribution,
            'periods' => $periods,
            'participants' => $paginatedParticipants,
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Internal sync logic to aggregate source transactions.
     */
    private function executeSync(Event $event, Carbon $startDate): array
    {
        $startPeriodKey = $startDate->format('Y-m');
        $skippedCount = 0;
        $totalTransactions = 0;
        $insertedRows = 0;

        DB::transaction(function () use ($event, $startDate, $startPeriodKey, &$skippedCount, &$totalTransactions, &$insertedRows) {
            // Delete only participants for periods being re-synced
            EventParticipant::where('event_id', $event->id)
                ->where('period_key', '>=', $startPeriodKey)
                ->delete();

            // Read source transactions (voucher buyers only: ND-%, voucher_plan_id NOT NULL, customer_phone NOT NULL, success only)
            $transactions = Transaction::where('external_id', 'like', 'ND-%')
                ->whereNotNull('voucher_plan_id')
                ->whereNotNull('customer_phone')
                ->where('customer_phone', '!=', '')
                ->where('status', 'success')
                ->where('created_at', '>=', $startDate)
                ->select('customer_phone', 'amount', 'created_at')
                ->get();

            $totalTransactions = $transactions->count();
            $aggregated = [];

            foreach ($transactions as $tx) {
                $normalizedPhone = PhoneNumberService::normalize($tx->customer_phone);

                if ($normalizedPhone === null) {
                    $skippedCount++;
                    continue;
                }

                $periodKey = Carbon::parse($tx->created_at)->format('Y-m');
                $groupKey = $normalizedPhone . '|' . $periodKey;

                if (!isset($aggregated[$groupKey])) {
                    $aggregated[$groupKey] = [
                        'phone' => $normalizedPhone,
                        'period_key' => $periodKey,
                        'total_purchase' => 0,
                        'transaction_count' => 0,
                        'first_transaction_at' => $tx->created_at,
                        'last_transaction_at' => $tx->created_at,
                    ];
                }

                $aggregated[$groupKey]['total_purchase'] += (float) $tx->amount;
                $aggregated[$groupKey]['transaction_count']++;

                if ($tx->created_at < $aggregated[$groupKey]['first_transaction_at']) {
                    $aggregated[$groupKey]['first_transaction_at'] = $tx->created_at;
                }
                if ($tx->created_at > $aggregated[$groupKey]['last_transaction_at']) {
                    $aggregated[$groupKey]['last_transaction_at'] = $tx->created_at;
                }
            }

            $now = Carbon::now();
            $batchInsert = [];

            foreach ($aggregated as $data) {
                $batchInsert[] = [
                    'event_id' => $event->id,
                    'phone' => $data['phone'],
                    'period_key' => $data['period_key'],
                    'total_purchase' => $data['total_purchase'],
                    'transaction_count' => $data['transaction_count'],
                    'first_transaction_at' => $data['first_transaction_at'],
                    'last_transaction_at' => $data['last_transaction_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($batchInsert, 500) as $chunk) {
                EventParticipant::insert($chunk);
            }

            $insertedRows = count($batchInsert);
            $uniquePhones = count(array_unique(array_column($batchInsert, 'phone')));

            $event->update([
                'last_synced_at' => $now,
                'sync_stats' => [
                    'start_date_synced' => $startDate->toDateString(),
                    'total_transactions' => $totalTransactions,
                    'skipped' => $skippedCount,
                    'unique_phones' => $uniquePhones,
                    'participant_rows' => $insertedRows,
                    'synced_at' => $now->toIso8601String(),
                ],
            ]);
        });

        return [
            'start_date' => $startDate->toDateString(),
            'total_transactions' => $totalTransactions,
            'skipped' => $skippedCount,
            'participant_rows' => $insertedRows,
            'valid_transactions' => $totalTransactions - $skippedCount,
        ];
    }

    /**
     * Calculate purchase distribution buckets.
     */
    private function calculateDistribution($participants)
    {
        $buckets = [
            ['range' => '< Rp25.000', 'min' => 0, 'max' => 24999.99, 'count' => 0],
            ['range' => 'Rp25.000 – Rp49.999', 'min' => 25000, 'max' => 49999.99, 'count' => 0],
            ['range' => 'Rp50.000 – Rp74.999', 'min' => 50000, 'max' => 74999.99, 'count' => 0],
            ['range' => 'Rp75.000 – Rp99.999', 'min' => 75000, 'max' => 99999.99, 'count' => 0],
            ['range' => 'Rp100.000 – Rp149.999', 'min' => 100000, 'max' => 149999.99, 'count' => 0],
            ['range' => 'Rp150.000+', 'min' => 150000, 'max' => PHP_FLOAT_MAX, 'count' => 0],
        ];

        foreach ($participants as $p) {
            $amount = (float) $p->total_purchase;
            foreach ($buckets as &$bucket) {
                if ($amount >= $bucket['min'] && $amount <= $bucket['max']) {
                    $bucket['count']++;
                    break;
                }
            }
        }

        return array_map(function ($b) {
            return ['range' => $b['range'], 'count' => $b['count']];
        }, $buckets);
    }

    /**
     * Get reward rules for an event.
     * GET /admin/events/{id}/reward-rules
     */
    public function getRewardRules($id)
    {
        $event = Event::findOrFail($id);
        $rules = $event->rewardRules()->with('voucherPlan')->get();
        return response()->json($rules);
    }

    /**
     * Create a new reward rule.
     * POST /admin/events/{id}/reward-rules
     */
    public function storeRewardRule(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'voucher_plan_id' => 'required|exists:voucher_plans,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rule = EventRewardRule::create([
            'event_id' => $event->id,
            'voucher_plan_id' => $request->voucher_plan_id,
            'name' => $request->name,
            'description' => $request->description,
            'is_active' => $request->input('is_active', true),
        ]);

        $retroactiveStats = null;
        // Requirement #6: REWARD RULE CREATED AFTER CUSTOMER ALREADY REACHED TARGET
        // When active rule is created, immediately process existing eligible participants
        if ($rule->is_active) {
            try {
                $retroactiveStats = app(RewardService::class)->processExistingEligible($rule);
            } catch (\Throwable $e) {
                Log::warning('RewardService: Retroactive processing on rule creation failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'rule' => $rule->load('voucherPlan'),
            'retroactive_stats' => $retroactiveStats,
            'message' => 'Reward rule berhasil dibuat.',
        ], 201);
    }

    /**
     * Update an existing reward rule.
     * PUT /admin/events/{id}/reward-rules/{ruleId}
     */
    public function updateRewardRule(Request $request, $id, $ruleId)
    {
        $event = Event::findOrFail($id);
        $rule = EventRewardRule::where('event_id', $event->id)->findOrFail($ruleId);

        $validator = Validator::make($request->all(), [
            'voucher_plan_id' => 'sometimes|required|exists:voucher_plans,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $wasInactive = !$rule->is_active;

        $rule->update($request->only([
            'voucher_plan_id', 'name', 'description', 'is_active'
        ]));

        $retroactiveStats = null;
        // If activated from inactive state, retroactively process eligible customers
        if ($wasInactive && $rule->is_active) {
            try {
                $retroactiveStats = app(RewardService::class)->processExistingEligible($rule);
            } catch (\Throwable $e) {
                Log::warning('RewardService: Retroactive processing on rule activation failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'rule' => $rule->load('voucherPlan'),
            'retroactive_stats' => $retroactiveStats,
            'message' => 'Reward rule berhasil diperbarui.',
        ]);
    }

    /**
     * Delete a reward rule.
     * DELETE /admin/events/{id}/reward-rules/{ruleId}
     */
    public function deleteRewardRule($id, $ruleId)
    {
        $event = Event::findOrFail($id);
        $rule = EventRewardRule::where('event_id', $event->id)->findOrFail($ruleId);
        $rule->delete();

        return response()->json(['message' => 'Reward rule berhasil dihapus.']);
    }

    /**
     * Manually trigger retroactive processing for eligible customers under a rule.
     * POST /admin/events/{id}/reward-rules/{ruleId}/process-eligible
     */
    public function processRetroactiveRewards($id, $ruleId)
    {
        $event = Event::findOrFail($id);
        $rule = EventRewardRule::where('event_id', $event->id)->findOrFail($ruleId);

        $stats = app(RewardService::class)->processExistingEligible($rule);

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'message' => "Proses reward selesai. {$stats['issued']} diterbitkan, {$stats['skipped']} dilewati, {$stats['failed']} gagal.",
        ]);
    }

    /**
     * Retry a failed reward issuance.
     * POST /admin/events/{id}/rewards/{rewardId}/retry
     */
    public function retryReward($id, $rewardId)
    {
        $event = Event::findOrFail($id);
        $reward = EventReward::where('event_id', $event->id)->with('rule')->findOrFail($rewardId);

        if ($reward->status === 'issued') {
            return response()->json(['message' => 'Reward sudah berstatus issued.'], 400);
        }

        $rule = $reward->rule;
        if (!$rule) {
            return response()->json(['message' => 'Reward rule tidak ditemukan.'], 404);
        }

        $res = app(RewardService::class)->processSingleReward($event, $rule, $reward->phone, $reward->period_key);

        return response()->json([
            'success' => $res && $res->status === 'issued',
            'reward' => $res,
            'message' => ($res && $res->status === 'issued') ? 'Reward berhasil diterbitkan.' : 'Penerbitan reward gagal: ' . ($res?->error_message ?? 'Error'),
        ]);
    }

    /**
     * Check Loyalty Test Mode status and config.
     * GET /admin/events/{id}/loyalty-test/status
     */
    public function getLoyaltyTestStatus($id)
    {
        $event = Event::findOrFail($id);
        $enabled = env('LOYALTY_TEST_MODE', false) === true || env('LOYALTY_TEST_MODE', false) === 'true';
        $whatsappEnabled = env('LOYALTY_TEST_WHATSAPP', false) === true || env('LOYALTY_TEST_WHATSAPP', false) === 'true';

        $activeTestStates = \App\Models\EventTestState::where('event_id', $event->id)->count();
        $testRewardsCount = \App\Models\EventReward::where('event_id', $event->id)->where('is_test', true)->count();

        return response()->json([
            'enabled' => $enabled,
            'whatsapp_enabled' => $whatsappEnabled,
            'active_test_states' => $activeTestStates,
            'test_rewards_count' => $testRewardsCount,
            'event_target_amount' => (float) $event->target_amount,
        ]);
    }

    /**
     * Run isolated Loyalty Test simulation.
     * POST /admin/events/{id}/loyalty-test/run
     */
    public function runLoyaltyTest(Request $request, $id)
    {
        $enabled = env('LOYALTY_TEST_MODE', false) === true || env('LOYALTY_TEST_MODE', false) === 'true';
        if (!$enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Loyalty Test Mode is disabled. Set LOYALTY_TEST_MODE=true in .env to enable.',
            ], 403);
        }

        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
            'amount' => 'required|numeric|min:0',
            'period_key' => 'nullable|string|regex:/^\d{4}-\d{2}$/',
            'use_real_mikrotik' => 'nullable|boolean',
            'expiry_minutes' => 'nullable|integer|min:1|max:43200',
            'send_whatsapp' => 'nullable|boolean',
        ], [
            'phone.required' => 'Nomor HP/WhatsApp wajib diisi.',
            'amount.required' => 'Nominal simulasi belanja wajib diisi.',
            'amount.numeric' => 'Nominal belanja harus berupa angka.',
            'amount.min' => 'Nominal belanja minimal 0.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $periodKey = $request->input('period_key') ?: Carbon::now()->format('Y-m');

        $options = [
            'use_real_mikrotik' => $request->boolean('use_real_mikrotik'),
            'expiry_minutes' => $request->input('expiry_minutes'),
            'send_whatsapp' => $request->boolean('send_whatsapp'),
        ];

        $result = app(RewardService::class)->runLoyaltyTest(
            $event,
            $request->phone,
            $periodKey,
            (float) $request->amount,
            $options
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Reset isolated Loyalty Test data.
     * POST /admin/events/{id}/loyalty-test/reset
     */
    public function resetLoyaltyTest(Request $request, $id)
    {
        $enabled = env('LOYALTY_TEST_MODE', false) === true || env('LOYALTY_TEST_MODE', false) === 'true';
        if (!$enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Loyalty Test Mode is disabled. Set LOYALTY_TEST_MODE=true in .env to enable.',
            ], 403);
        }

        $event = Event::findOrFail($id);
        $phone = $request->input('phone');

        $result = app(RewardService::class)->resetLoyaltyTest($event, $phone);

        return response()->json($result);
    }
}
