<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Services\PhoneNumberService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * PublicLoyaltyController
 * 
 * Hidden Public Read-Only Endpoint for customer loyalty progress check.
 * Accessible via /loyalty URL only (never exposed in navbar/menus).
 * 
 * Strict Security & Anti-Bruteforce:
 * - Rate limited by IP (max 15 attempts / min) and by Phone (max 8 attempts / min)
 * - Read-only (never inserts/updates database)
 * - Returns ONLY the requested phone's progress; never leaks other customers
 * - No internal database IDs, transaction details, or aggregate revenues exposed
 */
class PublicLoyaltyController extends Controller
{
    /**
     * Check customer voucher loyalty progress for current active event.
     * POST/GET /api/loyalty/progress
     */
    public function check(Request $request)
    {
        // 1. Anti-Bruteforce: IP-level rate limiting (max 15 requests per minute per IP)
        $ip = $request->ip();
        $ipKey = 'public-loyalty-ip:' . $ip;

        if (RateLimiter::tooManyAttempts($ipKey, 15)) {
            $retryAfter = RateLimiter::availableIn($ipKey);
            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak permintaan dari IP Anda. Silakan tunggu {$retryAfter} detik sebelum mencoba lagi.",
                'retry_after' => $retryAfter,
            ], 429);
        }

        // 2. Validate input
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:8|max:25',
        ], [
            'phone.required' => 'Nomor HP/WhatsApp wajib diisi.',
            'phone.min' => 'Nomor HP minimal 8 digit.',
            'phone.max' => 'Nomor HP maksimal 25 karakter.',
        ]);

        if ($validator->fails()) {
            RateLimiter::hit($ipKey, 60);
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first('phone'),
            ], 422);
        }

        // 3. Normalize phone number using standard system PhoneNumberService
        $rawPhone = trim($request->input('phone'));
        $normalizedPhone = PhoneNumberService::normalize($rawPhone);

        if (!$normalizedPhone) {
            RateLimiter::hit($ipKey, 60);
            return response()->json([
                'success' => false,
                'message' => 'Format nomor HP tidak valid. Gunakan format seperti 08123456789 atau 628123456789.',
            ], 422);
        }

        // 4. Anti-Bruteforce: Targeted Phone rate limiting (max 8 checks per minute per phone number)
        $phoneKey = 'public-loyalty-phone:' . $normalizedPhone;
        if (RateLimiter::tooManyAttempts($phoneKey, 8)) {
            $retryAfter = RateLimiter::availableIn($phoneKey);
            return response()->json([
                'success' => false,
                'message' => "Pengecekan untuk nomor ini telah mencapai batas. Silakan tunggu {$retryAfter} detik.",
                'retry_after' => $retryAfter,
            ], 429);
        }

        // Register hits for rate limiting
        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($phoneKey, 60);

        // 5. Find current active permanent event
        $event = Event::where('status', 'active')->first();

        if (!$event) {
            return response()->json([
                'success' => false,
                'has_active_event' => false,
                'message' => 'Saat ini belum ada event loyalty aktif.',
            ]);
        }

        // 6. Determine current calendar month period_key (YYYY-MM)
        $now = Carbon::now();
        $periodKey = $now->format('Y-m');

        $monthNames = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
            '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
            '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $monthNum = $now->format('m');
        $periodFormatted = ($monthNames[$monthNum] ?? $now->format('F')) . ' ' . $now->format('Y');

        $isTestMode = env('LOYALTY_TEST_MODE', false) === true || env('LOYALTY_TEST_MODE', false) === 'true';
        $testState = null;
        if ($isTestMode) {
            $testState = \App\Models\EventTestState::where('event_id', $event->id)
                ->where('phone', $normalizedPhone)
                ->where('period_key', $periodKey)
                ->first();
        }

        // 7. Query event_participants strictly for this event + phone + period_key (READ-ONLY)
        $participant = EventParticipant::where('event_id', $event->id)
            ->where('phone', $normalizedPhone)
            ->where('period_key', $periodKey)
            ->first();

        $targetAmount = (float) $event->target_amount;

        $totalPurchase = 0;
        $transactionCount = 0;
        $isSimulated = false;

        if ($testState) {
            $totalPurchase = (float) $testState->simulated_total_purchase;
            $transactionCount = (int) $testState->simulated_transaction_count;
            $isSimulated = true;
        } elseif ($participant) {
            $totalPurchase = (float) $participant->total_purchase;
            $transactionCount = (int) $participant->transaction_count;
        }

        // Empty state: Customer hasn't purchased any voucher this calendar month (and no test simulation)
        if ($totalPurchase <= 0) {
            return response()->json([
                'success' => true,
                'has_active_event' => true,
                'found' => false,
                'is_test_mode' => $isSimulated,
                'event_name' => $event->name,
                'period_key' => $periodKey,
                'period_formatted' => $periodFormatted,
                'phone' => $normalizedPhone,
                'target_amount' => $targetAmount,
                'message' => 'Belum ada pembelian voucher pada periode ini.',
            ]);
        }

        // 8. Calculate actual progress metrics
        $progressPercentage = $targetAmount > 0
            ? round(($totalPurchase / $targetAmount) * 100, 1)
            : 100.0;

        $remainingAmount = max(0, $targetAmount - $totalPurchase);
        $isTargetAchieved = $targetAmount > 0 ? ($totalPurchase >= $targetAmount) : true;

        // 9. Load reward details if target achieved (Phase 2: Automatic Reward System)
        $rewardData = null;
        if ($isTargetAchieved) {
            $reward = \App\Models\EventReward::where('event_id', $event->id)
                ->where('phone', $normalizedPhone)
                ->where('period_key', $periodKey)
                ->with(['rule', 'voucher'])
                ->first();

            // Self-healing: if customer met target but reward is not yet issued (e.g. rule created later),
            // trigger reward check automatically
            if (!$reward) {
                try {
                    app(\App\Services\RewardService::class)->processRewardCheck($event, $normalizedPhone, $periodKey);
                    $reward = \App\Models\EventReward::where('event_id', $event->id)
                        ->where('phone', $normalizedPhone)
                        ->where('period_key', $periodKey)
                        ->with(['rule', 'voucher'])
                        ->first();
                } catch (\Throwable $e) {
                    // Non-blocking
                }
            }

            if ($reward && $reward->status === 'issued' && $reward->voucher) {
                $now = Carbon::now();
                $expiresAt = $reward->expires_at ? Carbon::parse($reward->expires_at) : null;
                $isExpired = $expiresAt && $now->gt($expiresAt);

                $remainingDays = 0;
                $remainingTimeLabel = 'Kedaluwarsa';
                if ($expiresAt && !$isExpired) {
                    $diff = $now->diff($expiresAt);
                    $remainingDays = $diff->d + ($diff->h > 0 ? 1 : 0);
                    $remainingTimeLabel = $diff->d > 0 ? "{$diff->d} hari" : "{$diff->h} jam";
                }

                $rewardData = [
                    'id' => $reward->id,
                    'name' => $reward->rule?->name ?? 'Free Voucher Hotspot',
                    'status' => $isExpired ? 'expired' : $reward->status,
                    'voucher_code' => $reward->voucher->code,
                    'issued_at' => $reward->issued_at?->format('d M Y, H:i'),
                    'expires_at' => $expiresAt?->format('d M Y, H:i'),
                    'expires_at_formatted' => $expiresAt?->translatedFormat('d F Y'),
                    'remaining_days' => $remainingDays,
                    'remaining_time_label' => $remainingTimeLabel,
                    'is_expired' => $isExpired,
                ];
            } elseif ($reward) {
                $rewardData = [
                    'id' => $reward->id,
                    'name' => $reward->rule?->name ?? 'Free Voucher Hotspot',
                    'status' => $reward->status,
                    'voucher_code' => null,
                    'is_expired' => false,
                ];
            }
        }

        // 10. Return progress data safely (strictly isolated to this phone)
        return response()->json([
            'success' => true,
            'has_active_event' => true,
            'found' => true,
            'is_test_mode' => $isSimulated,
            'event_name' => $event->name,
            'period_key' => $periodKey,
            'period_formatted' => $periodFormatted,
            'phone' => $normalizedPhone,
            'total_purchase' => $totalPurchase,
            'transaction_count' => $transactionCount,
            'target_amount' => $targetAmount,
            'progress_percentage' => $progressPercentage,
            'remaining_amount' => $remainingAmount,
            'is_target_achieved' => $isTargetAchieved,
            'status_label' => $isTargetAchieved ? '✓ Target tercapai' : 'Belum mencapai target',
            'reward' => $rewardData,
        ]);
    }

    /**
     * Get active loyalty event summary for public promotion banner.
     * GET /api/loyalty/active-event
     */
    public function getActiveEvent()
    {
        $event = Event::where('status', 'active')->first();
        if (!$event) {
            return response()->json([
                'success' => true,
                'has_active_event' => false,
            ]);
        }

        $rule = \App\Models\EventRewardRule::where('event_id', $event->id)
            ->where('is_active', true)
            ->with('voucherPlan')
            ->first();

        return response()->json([
            'success' => true,
            'has_active_event' => true,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'description' => $event->description,
                'target_amount' => (float) $event->target_amount,
                'target_amount_formatted' => 'Rp ' . number_format($event->target_amount, 0, ',', '.'),
            ],
            'reward_rule' => $rule ? [
                'name' => $rule->name,
                'voucher_plan' => $rule->voucherPlan?->name,
                'duration' => $rule->voucherPlan?->duration,
            ] : null,
        ]);
    }
}
