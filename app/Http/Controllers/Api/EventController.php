<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Transaction;
use App\Services\PhoneNumberService;
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

        // Provide real unmasked phone numbers in response
        $paginatedParticipants->getCollection()->transform(function ($p) {
            $p->avg_per_transaction = $p->transaction_count > 0
                ? round($p->total_purchase / $p->transaction_count, 2)
                : 0;
            $p->masked_phone = $p->phone;
            return $p;
        });

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
            'target_summary' => [
                'target_amount' => $targetAmount,
                'qualifying_customers' => $targetQualifying,
                'total_customers' => $totalUniqueCustomers,
                'percentage' => $targetPercentage,
            ],
            'distribution' => $distribution,
            'periods' => $periods,
            'participants' => $paginatedParticipants,
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Target qualification query (read-only calculation).
     * GET /admin/events/{id}/simulate?target=50000&period=2026-09
     * 
     * Counts how many customers reach a target amount per month.
     * Evaluated per customer per month.
     */
    public function simulate(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'target' => 'required|numeric|min:0',
            'period' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $target = (float) $request->target;

        $query = EventParticipant::where('event_id', $event->id);
        if ($request->filled('period')) {
            $query->where('period_key', $request->period);
        }

        $qualifying = (clone $query)
            ->where('total_purchase', '>=', $target)
            ->distinct('phone')
            ->count('phone');

        $totalCustomers = (clone $query)
            ->distinct('phone')
            ->count('phone');

        $percentage = $totalCustomers > 0
            ? round(($qualifying / $totalCustomers) * 100, 1)
            : 0;

        return response()->json([
            'target_amount' => $target,
            'period' => $request->period ?? 'all',
            'qualifying_customers' => $qualifying,
            'total_customers' => $totalCustomers,
            'percentage' => $percentage,
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
}
