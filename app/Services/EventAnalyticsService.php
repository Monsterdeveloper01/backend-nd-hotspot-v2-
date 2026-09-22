<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Transaction;
use App\Services\PhoneNumberService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EventAnalyticsService
 * 
 * Phase 1: Event System Analytics — Automatic Tracking
 * 
 * Called after a voucher transaction succeeds to automatically update
 * event participant aggregation. Customer does NOT know this is happening.
 * 
 * This service:
 * - Finds active events covering the transaction date
 * - Normalizes the phone number
 * - Recalculates the monthly aggregation from source transactions (idempotent)
 * - Does NOT send notifications, rewards, or expose anything to customer
 * 
 * The recalculation approach ensures idempotency:
 * Instead of doing total += amount (which doubles on duplicate callback),
 * we query ALL source transactions for that phone+period and recalculate.
 */
class EventAnalyticsService
{
    /**
     * Process a successful voucher transaction for event analytics.
     * 
     * Called from TransactionController::callback() after voucher payment success.
     * Safe to call multiple times for the same transaction (idempotent).
     * 
     * @param Transaction $transaction The successful transaction
     * @return void
     */
    public static function processTransaction(Transaction $transaction): void
    {
        try {
            // Only process voucher transactions (ND-%)
            if (!str_starts_with($transaction->external_id, 'ND-')) {
                return;
            }

            // Only process successful transactions
            if ($transaction->status !== 'success') {
                return;
            }

            // Normalize phone
            $phone = PhoneNumberService::normalize($transaction->customer_phone);
            if ($phone === null) {
                Log::info('EventAnalytics: Skipped transaction with invalid phone', [
                    'external_id' => $transaction->external_id,
                    'phone' => $transaction->customer_phone,
                ]);
                return;
            }

            $txDate = Carbon::parse($transaction->created_at);
            $periodKey = $txDate->format('Y-m');

            // Find all active events (permanent, ongoing tracking)
            $events = Event::where('status', 'active')->get();

            if ($events->isEmpty()) {
                // No active event — silently do nothing
                return;
            }

            foreach ($events as $event) {
                self::recalculateParticipant($event, $phone, $periodKey);
            }

        } catch (\Exception $e) {
            // NEVER let analytics errors affect the payment flow
            Log::error('EventAnalytics: processTransaction failed', [
                'external_id' => $transaction->external_id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recalculate a single participant's monthly aggregation from source transactions.
     * 
     * This is IDEMPOTENT: it queries all source transactions for the given
     * phone + period_key within the calendar month, and replaces (UPSERT) the
     * participant record with fresh totals.
     * 
     * If the same callback fires twice for ND-ABC123, the recalculation
     * will produce the same result because it reads from the source.
     * 
     * @param Event  $event     The event to update
     * @param string $phone     Normalized phone (628xxx)
     * @param string $periodKey YYYY-MM
     * @return void
     */
    private static function recalculateParticipant(Event $event, string $phone, string $periodKey): void
    {
        try {
            // Date range for this specific period_key (calendar month)
            $rangeStart = Carbon::createFromFormat('Y-m', $periodKey)->startOfMonth();
            $rangeEnd = $rangeStart->copy()->endOfMonth()->addDay()->startOfDay();

            // Find ALL matching phone variants in source transactions
            // We need to match transactions where normalize(customer_phone) === $phone
            // Since phones are stored in various formats, we query broadly and filter
            $possiblePrefixes = self::getPhonePrefixes($phone);

            $query = Transaction::where('external_id', 'like', 'ND-%')
                ->where('status', 'success')
                ->where('created_at', '>=', $rangeStart)
                ->where('created_at', '<', $rangeEnd);

            // Build WHERE clause for phone matching
            $query->where(function ($q) use ($possiblePrefixes) {
                foreach ($possiblePrefixes as $prefix) {
                    $q->orWhere('customer_phone', $prefix);
                }
            });

            $transactions = $query->get();

            // Filter to only transactions whose normalized phone matches exactly
            $matchingTx = $transactions->filter(function ($tx) use ($phone) {
                return PhoneNumberService::normalize($tx->customer_phone) === $phone;
            });

            if ($matchingTx->isEmpty()) {
                // No matching transactions — remove participant record if exists
                EventParticipant::where('event_id', $event->id)
                    ->where('phone', $phone)
                    ->where('period_key', $periodKey)
                    ->delete();
                return;
            }

            // Calculate aggregation
            $totalPurchase = $matchingTx->sum('amount');
            $transactionCount = $matchingTx->count();
            $firstTx = $matchingTx->min('created_at');
            $lastTx = $matchingTx->max('created_at');

            // UPSERT: update if exists, create if not
            $now = Carbon::now();

            EventParticipant::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'phone' => $phone,
                    'period_key' => $periodKey,
                ],
                [
                    'total_purchase' => $totalPurchase,
                    'transaction_count' => $transactionCount,
                    'first_transaction_at' => $firstTx,
                    'last_transaction_at' => $lastTx,
                    'updated_at' => $now,
                ]
            );

        } catch (\Exception $e) {
            Log::error('EventAnalytics: recalculateParticipant failed', [
                'event_id' => $event->id,
                'phone' => $phone,
                'period_key' => $periodKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate all possible stored phone formats for a normalized phone.
     * 
     * Since customer_phone is stored in various formats in the transactions table,
     * we need to search for all variants that would normalize to the same number.
     * 
     * Example: normalized "628123456789" could be stored as:
     *   - "628123456789"
     *   - "08123456789"
     *   - "+628123456789"
     *   - "8123456789"
     * 
     * @param string $normalizedPhone Phone in 628xxx format
     * @return array Possible stored formats
     */
    private static function getPhonePrefixes(string $normalizedPhone): array
    {
        // Remove the leading "62" to get the core number
        $core = substr($normalizedPhone, 2); // "8123456789"

        return [
            $normalizedPhone,         // 628123456789
            '0' . $core,              // 08123456789
            '+' . $normalizedPhone,   // +628123456789
            $core,                    // 8123456789
        ];
    }
}
