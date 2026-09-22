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
 * Phase 1: Event System Analytics
 * Internal admin-only controller for managing analytics events,
 * syncing transaction data, and viewing purchase pattern analytics.
 * 
 * NO reward logic. NO customer-facing features.
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
                    'start_date' => $event->start_date->format('Y-m-d'),
                    'end_date' => $event->end_date->format('Y-m-d'),
                    'target_amount' => $event->target_amount,
                    'status' => $event->status,
                    'last_synced_at' => $event->last_synced_at?->toIso8601String(),
                    'sync_stats' => $event->sync_stats,
                    'participants_count' => $event->participants_count,
                    'unique_customers' => $uniqueCustomers,
                    'created_at' => $event->created_at->toIso8601String(),
                ];
            });

        return response()->json($events);
    }

    /**
     * Create a new event.
     * POST /admin/events
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'target_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,active,ended',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event = Event::create([
            'name' => $request->name,
            'description' => $request->description,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'target_amount' => $request->target_amount ?? 0,
            'status' => $request->status ?? 'draft',
        ]);

        return response()->json($event, 201);
    }

    /**
     * Update an existing event.
     * PUT /admin/events/{id}
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'target_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,active,ended',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event->update($request->only([
            'name', 'description', 'start_date', 'end_date', 'target_amount', 'status'
        ]));

        return response()->json($event);
    }

    /**
     * Delete an event (draft only).
     * DELETE /admin/events/{id}
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        if ($event->status !== 'draft') {
            return response()->json([
                'message' => 'Hanya event dengan status draft yang dapat dihapus.'
            ], 422);
        }

        $event->delete();

        return response()->json(['message' => 'Event berhasil dihapus.']);
    }

    /**
     * Sync transaction data into event_participants.
     * POST /admin/events/{id}/sync
     * 
     * IDEMPOTENT: DELETE existing + rebuild from source transactions.
     * Wrapped in DB::transaction for safety (rollback on error).
     * 
     * Source: transactions WHERE external_id LIKE 'ND-%' AND status = 'success'
     *         AND created_at within event period.
     */
    public function sync($id)
    {
        $event = Event::findOrFail($id);

        $startDate = Carbon::parse($event->start_date)->startOfDay();
        // Use end_date + 1 day at midnight to include all transactions on end_date
        $endDate = Carbon::parse($event->end_date)->addDay()->startOfDay();

        $skippedCount = 0;
        $totalTransactions = 0;
        $insertedRows = 0;

        try {
            DB::transaction(function () use ($event, $startDate, $endDate, &$skippedCount, &$totalTransactions, &$insertedRows) {
                // Step 1: DELETE existing participants for this event
                EventParticipant::where('event_id', $event->id)->delete();

                // Step 2: Read source transactions (voucher only, success only)
                $transactions = Transaction::where('external_id', 'like', 'ND-%')
                    ->where('status', 'success')
                    ->where('created_at', '>=', $startDate)
                    ->where('created_at', '<', $endDate)
                    ->select('customer_phone', 'amount', 'created_at')
                    ->get();

                $totalTransactions = $transactions->count();

                // Step 3: Normalize phones and group
                $aggregated = [];

                foreach ($transactions as $tx) {
                    $normalizedPhone = PhoneNumberService::normalize($tx->customer_phone);

                    if ($normalizedPhone === null) {
                        $skippedCount++;
                        Log::info("EventSync: Skipped transaction with invalid phone", [
                            'event_id' => $event->id,
                            'phone' => $tx->customer_phone,
                        ]);
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

                // Step 4: Batch insert aggregated participants
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

                // Insert in chunks to avoid packet size issues
                foreach (array_chunk($batchInsert, 500) as $chunk) {
                    EventParticipant::insert($chunk);
                }

                $insertedRows = count($batchInsert);

                // Step 5: Update event metadata
                $uniquePhones = count(array_unique(array_column($batchInsert, 'phone')));

                $event->update([
                    'last_synced_at' => $now,
                    'sync_stats' => [
                        'total_transactions' => $totalTransactions,
                        'skipped' => $skippedCount,
                        'unique_phones' => $uniquePhones,
                        'participant_rows' => $insertedRows,
                        'synced_at' => $now->toIso8601String(),
                    ],
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Sync berhasil.',
                'stats' => [
                    'total_transactions' => $totalTransactions,
                    'skipped' => $skippedCount,
                    'participant_rows' => $insertedRows,
                    'valid_transactions' => $totalTransactions - $skippedCount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("EventSync failed", [
                'event_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync gagal. Data sebelumnya tetap aman (rollback).',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get analytics for an event.
     * GET /admin/events/{id}/analytics
     */
    public function analytics(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $participantsQuery = EventParticipant::where('event_id', $event->id);

        // Filter by period
        if ($request->filled('period')) {
            $participantsQuery->where('period_key', $request->period);
        }

        $allParticipants = $participantsQuery->get();

        // === SUMMARY METRICS ===

        // Total unique customers (across all periods in this event)
        $totalUniqueCustomers = EventParticipant::where('event_id', $event->id)
            ->when($request->filled('period'), fn($q) => $q->where('period_key', $request->period))
            ->distinct('phone')
            ->count('phone');

        $totalTransactions = $allParticipants->sum('transaction_count');
        $totalRevenue = $allParticipants->sum('total_purchase');

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

        // === AVAILABLE PERIODS ===
        $periods = EventParticipant::where('event_id', $event->id)
            ->distinct('period_key')
            ->orderBy('period_key')
            ->pluck('period_key');

        // === PARTICIPANTS TABLE (with masking & pagination) ===
        $participantsTableQuery = EventParticipant::where('event_id', $event->id)
            ->when($request->filled('period'), fn($q) => $q->where('period_key', $request->period))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim($request->search);
                // Normalize search input to match stored normalized phones
                $normalizedSearch = PhoneNumberService::normalize($search);
                if ($normalizedSearch) {
                    $q->where('phone', 'like', "%{$normalizedSearch}%");
                } else {
                    // Fallback: search raw digits
                    $digits = preg_replace('/[^0-9]/', '', $search);
                    if ($digits !== '') {
                        $q->where('phone', 'like', "%{$digits}%");
                    }
                }
            })
            ->orderByDesc('total_purchase');

        $paginatedParticipants = $participantsTableQuery->paginate(20);

        // Mask phone numbers in response
        $paginatedParticipants->getCollection()->transform(function ($p) {
            $p->masked_phone = PhoneNumberService::mask($p->phone);
            $p->avg_per_transaction = $p->transaction_count > 0
                ? round($p->total_purchase / $p->transaction_count, 2)
                : 0;
            // Do NOT expose raw phone in API response
            unset($p->phone);
            return $p;
        });

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'start_date' => $event->start_date->format('Y-m-d'),
                'end_date' => $event->end_date->format('Y-m-d'),
                'target_amount' => $event->target_amount,
                'status' => $event->status,
                'last_synced_at' => $event->last_synced_at?->toIso8601String(),
                'sync_stats' => $event->sync_stats,
            ],
            'summary' => [
                'total_unique_customers' => $totalUniqueCustomers,
                'total_transactions' => $totalTransactions,
                'total_revenue' => round($totalRevenue, 2),
                'avg_purchase_per_customer_month' => $avgPurchasePerCustomerMonth,
                'median_purchase_per_customer_month' => $medianPurchase,
                'highest_monthly_purchase' => round($highestMonthlyPurchase, 2),
            ],
            'distribution' => $distribution,
            'periods' => $periods,
            'participants' => $paginatedParticipants,
        ]);
    }

    /**
     * Target simulation (read-only, no DB changes).
     * GET /admin/events/{id}/simulate?target=50000
     * 
     * Counts how many customer-months qualify for a given target amount.
     * Each customer-month is evaluated independently.
     */
    public function simulate(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'target' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $target = (float) $request->target;

        // Count customer-months that qualify
        $qualifying = EventParticipant::where('event_id', $event->id)
            ->where('total_purchase', '>=', $target)
            ->distinct('phone')
            ->count('phone');

        $totalCustomers = EventParticipant::where('event_id', $event->id)
            ->distinct('phone')
            ->count('phone');

        $percentage = $totalCustomers > 0
            ? round(($qualifying / $totalCustomers) * 100, 1)
            : 0;

        return response()->json([
            'target_amount' => $target,
            'qualifying_customers' => $qualifying,
            'total_customers' => $totalCustomers,
            'percentage' => $percentage,
        ]);
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

        // Return only range + count (remove internal min/max)
        return array_map(function ($b) {
            return ['range' => $b['range'], 'count' => $b['count']];
        }, $buckets);
    }
}
