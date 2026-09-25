<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventReward;
use App\Models\EventRewardRule;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RewardService
 * 
 * Phase 2: Automatic Reward System
 * 
 * Business Rules:
 * 1. Target amount comes ONLY from events.target_amount.
 * 2. Fully automatic: total_purchase >= events.target_amount => customer eligible => voucher reward issued.
 *    No manual claim, no claim button, no /claim-reward.
 * 3. Separation of DB transactions from external operations (MikroTik).
 *    State machine: processing -> issued (if MikroTik & DB succeed) or processing -> failed.
 * 4. Strict idempotency: exactly ONE reward per (event_id, phone, period_key, event_reward_rule_id).
 * 5. Reward vouchers have source = 'reward' and are NEVER counted in loyalty purchases.
 * 6. Voucher expiration is 5 days from issuance (issued_at + 5 days) in DB and MikroTik.
 * 7. Payment flow isolation: errors here never break purchase transactions.
 * 8. Non-blocking WhatsApp notifications after successful issuance.
 */
class RewardService
{
    protected MikrotikService $mikrotik;
    protected WhatsAppService $wa;

    public function __construct(MikrotikService $mikrotik, WhatsAppService $wa)
    {
        $this->mikrotik = $mikrotik;
        $this->wa = $wa;
    }

    /**
     * Check if a participant is eligible for rewards and process them automatically.
     * 
     * Called from EventAnalyticsService after recalculating a participant's totals.
     * 
     * @param Event  $event
     * @param string $phone     Normalized phone number (628xxx)
     * @param string $periodKey YYYY-MM
     * @return void
     */
    public function processRewardCheck(Event $event, string $phone, string $periodKey): void
    {
        try {
            $targetAmount = (float) $event->target_amount;
            if ($targetAmount <= 0) {
                return; // Target amount not set
            }

            // Find participant aggregation
            $participant = EventParticipant::where('event_id', $event->id)
                ->where('phone', $phone)
                ->where('period_key', $periodKey)
                ->first();

            if (!$participant || (float) $participant->total_purchase < $targetAmount) {
                return; // Not reached target yet
            }

            // Find active reward rules for this event
            $rules = EventRewardRule::where('event_id', $event->id)
                ->where('is_active', true)
                ->with('voucherPlan')
                ->get();

            if ($rules->isEmpty()) {
                return; // No active reward rules
            }

            foreach ($rules as $rule) {
                $this->processSingleReward($event, $rule, $phone, $periodKey);
            }

        } catch (\Throwable $e) {
            // NEVER let reward errors propagate to payment flow
            Log::error('RewardService: processRewardCheck exception', [
                'event_id' => $event->id,
                'phone' => $phone,
                'period_key' => $periodKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process reward for a single rule with state machine and idempotency.
     * 
     * @param Event           $event
     * @param EventRewardRule $rule
     * @param string          $phone
     * @param string          $periodKey
     * @return EventReward|null
     */
    public function processSingleReward(Event $event, EventRewardRule $rule, string $phone, string $periodKey): ?EventReward
    {
        // 1. Check existing reward record
        $existing = EventReward::where('event_id', $event->id)
            ->where('phone', $phone)
            ->where('period_key', $periodKey)
            ->where('event_reward_rule_id', $rule->id)
            ->first();

        if ($existing) {
            // If already issued, used, expired, or currently processing, do nothing
            if (in_array($existing->status, ['issued', 'used', 'expired', 'processing'])) {
                return $existing;
            }
            // If failed, reuse this record for retry
            $reward = $existing;
            $reward->update([
                'status' => 'processing',
                'error_message' => null,
            ]);
        } else {
            // Atomic creation with unique constraint handling
            try {
                $reward = EventReward::create([
                    'event_id' => $event->id,
                    'event_reward_rule_id' => $rule->id,
                    'phone' => $phone,
                    'period_key' => $periodKey,
                    'reward_type' => 'voucher',
                    'reward_value' => $rule->voucherPlan?->name ?? 'Reward Voucher',
                    'status' => 'processing',
                    'granted_at' => Carbon::now(),
                ]);
            } catch (QueryException $e) {
                // Duplicate entry caught by unique constraint (event_id, phone, period_key, event_reward_rule_id)
                Log::info('RewardService: Duplicate reward insert prevented by UNIQUE constraint', [
                    'event_id' => $event->id,
                    'phone' => $phone,
                    'rule_id' => $rule->id,
                ]);
                return EventReward::where('event_id', $event->id)
                    ->where('phone', $phone)
                    ->where('period_key', $periodKey)
                    ->where('event_reward_rule_id', $rule->id)
                    ->first();
            }
        }

        // 2. Prepare voucher details
        $plan = $rule->voucherPlan;
        if (!$plan) {
            $reward->update([
                'status' => 'failed',
                'error_message' => 'Paket voucher (voucher_plan) tidak ditemukan atau telah dihapus.',
            ]);
            Log::error('RewardService: VoucherPlan missing for rule', ['rule_id' => $rule->id]);
            return $reward;
        }

        $issuedAt = Carbon::now();
        // Mandatory requirement: exactly 5 days from issuance
        $expiresAt = (clone $issuedAt)->addDays(5);

        // Generate unique 6-character voucher code (uppercase alphanumeric, unambiguous)
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $voucherCode = substr(str_shuffle(str_repeat($chars, 6)), 0, 6);
        } while (Voucher::where('code', $voucherCode)->exists());

        // 3. External Side Effect: MikroTik User Creation
        $mikrotikId = null;
        $mikrotikSuccess = false;
        $errorMessage = null;

        if (env('VOUCHER_MODE', 'radius') === 'mikrotik') {
            try {
                $profileName = $plan->mikrotik_profile ?: $plan->name;

                Log::info('RewardService: Creating MikroTik user for reward voucher', [
                    'code' => $voucherCode,
                    'profile' => $profileName,
                    'phone' => $phone,
                    'expires_at' => $expiresAt->toDateTimeString(),
                ]);

                $mikrotikResult = $this->mikrotik->createUser([
                    'username' => $voucherCode,
                    'password' => '',
                    'profile' => $profileName,
                    'comment' => 'Reward: ' . substr($rule->name, 0, 25) . ' Exp:' . $expiresAt->format('d/m/Y'),
                    'limit_uptime' => '5d', // 5-day uptime expiration in MikroTik
                ]);

                Log::info('RewardService: MikroTik API Response', ['result' => $mikrotikResult]);

                if (is_array($mikrotikResult) && isset($mikrotikResult[0]['.id'])) {
                    $mikrotikId = $mikrotikResult[0]['.id'];
                    $mikrotikSuccess = true;
                } elseif (is_array($mikrotikResult) && !isset($mikrotikResult['!trap'])) {
                    $mikrotikId = $mikrotikResult[0]['.id'] ?? 'created';
                    $mikrotikSuccess = true;
                } else {
                    $errorMessage = isset($mikrotikResult['!trap'])
                        ? 'MikroTik Trap: ' . json_encode($mikrotikResult['!trap'])
                        : 'MikroTik router gagal membuat user hotspot.';
                    Log::error('RewardService: MikroTik createUser failed', ['response' => $mikrotikResult]);
                }
            } catch (\Throwable $e) {
                $errorMessage = 'MikroTik Exception: ' . $e->getMessage();
                Log::error('RewardService: MikroTik connection exception', ['error' => $errorMessage]);
                $mikrotikSuccess = false;
            }
        } else {
            // Non-mikrotik mode (radius or mock)
            $mikrotikSuccess = true;
        }

        // Definition of SUCCESS: Database voucher + MikroTik user + event_reward = issued
        if (!$mikrotikSuccess) {
            $reward->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);
            Log::warning('RewardService: Reward marked as failed due to MikroTik failure', [
                'reward_id' => $reward->id,
                'phone' => $phone,
            ]);
            return $reward;
        }

        // 4. Save Voucher in DB with source = 'reward'
        try {
            $voucher = Voucher::create([
                'voucher_plan_id' => $plan->id,
                'code' => $voucherCode,
                'price' => 0.00,
                'status' => 'sold', // Assigned to customer, usable immediately
                'source' => 'reward', // Strict requirement: source = 'reward'
                'customer_phone' => $phone,
                'mikrotik_id' => $mikrotikId,
                'expires_at' => $expiresAt,
            ]);

            // Transition state to 'issued'
            $reward->update([
                'status' => 'issued',
                'voucher_id' => $voucher->id,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'error_message' => null,
            ]);

            Log::info('RewardService: Reward voucher successfully issued', [
                'reward_id' => $reward->id,
                'voucher_id' => $voucher->id,
                'code' => $voucherCode,
                'phone' => $phone,
            ]);

        } catch (\Throwable $e) {
            $reward->update([
                'status' => 'failed',
                'error_message' => 'Gagal menyimpan voucher di database: ' . $e->getMessage(),
            ]);
            Log::error('RewardService: DB save voucher failed', ['error' => $e->getMessage()]);
            return $reward;
        }

        // 5. Realtime Socket.IO Broadcast to WA Gateway (for instant Admin & Public UI update)
        $this->broadcastRewardIssued($event, $reward, $voucher, $phone, $periodKey);

        // 6. WhatsApp Notification (non-blocking)
        $this->sendRewardWhatsAppNotification($rule, $voucherCode, $phone, $expiresAt);

        return $reward;
    }

    /**
     * Retroactive reward processing:
     * When an admin creates or activates a reward rule, find all existing participants
     * whose total_purchase >= events.target_amount, and automatically issue rewards
     * if not already issued.
     * 
     * Requirement #6: REWARD RULE CREATED AFTER CUSTOMER ALREADY REACHED TARGET
     * 
     * @param EventRewardRule $rule
     * @return array Summary of processed participants
     */
    public function processExistingEligible(EventRewardRule $rule): array
    {
        $event = $rule->event;
        if (!$event || (float) $event->target_amount <= 0) {
            return ['eligible' => 0, 'issued' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $targetAmount = (float) $event->target_amount;

        // Find all participants who already met or exceeded target
        $eligibleParticipants = EventParticipant::where('event_id', $event->id)
            ->where('total_purchase', '>=', $targetAmount)
            ->get();

        $stats = [
            'eligible' => $eligibleParticipants->count(),
            'issued' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($eligibleParticipants as $participant) {
            // Check if already has issued/processing reward
            $hasReward = EventReward::where('event_id', $event->id)
                ->where('event_reward_rule_id', $rule->id)
                ->where('phone', $participant->phone)
                ->where('period_key', $participant->period_key)
                ->whereIn('status', ['issued', 'used', 'expired', 'processing'])
                ->exists();

            if ($hasReward) {
                $stats['skipped']++;
                continue;
            }

            $reward = $this->processSingleReward($event, $rule, $participant->phone, $participant->period_key);

            if ($reward && $reward->status === 'issued') {
                $stats['issued']++;
            } else {
                $stats['failed']++;
            }
        }

        Log::info('RewardService: Retroactive processing completed', [
            'rule_id' => $rule->id,
            'event_id' => $event->id,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Broadcast reward issuance to Socket.IO realtime server.
     */
    protected function broadcastRewardIssued(Event $event, EventReward $reward, Voucher $voucher, string $phone, string $periodKey): void
    {
        try {
            $waGatewayUrl = env('WHATSAPP_GATEWAY_URL', 'http://localhost:5000');
            Http::timeout(1)->asJson()->post("{$waGatewayUrl}/broadcast-analytics", [
                'event_id' => $event->id,
                'phone' => $phone,
                'period_key' => $periodKey,
                'reward_issued' => true,
                'reward_id' => $reward->id,
                'voucher_code' => $voucher->code,
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RewardService: Realtime broadcast failed (non-blocking)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send WhatsApp notification to customer upon successful reward issuance.
     * Non-blocking: failures here do not affect the issued status of the reward.
     */
    protected function sendRewardWhatsAppNotification(EventRewardRule $rule, string $voucherCode, string $phone, Carbon $expiresAt): void
    {
        try {
            $formattedExp = $expiresAt->translatedFormat('d F Y, H:i') . ' WIB';

            $message = "🎉 *SELAMAT! TARGET TERCAPAI* 🎉\n\n" .
                "Halo Pelanggan Setia *ND-Hotspot*! 💡\n" .
                "Total pembelian voucher Anda telah mencapai target program bulanan kami.\n\n" .
                "🎁 *Reward Anda:* {$rule->name}\n" .
                "🎫 *Kode Voucher:* `{$voucherCode}`\n" .
                "⏳ *Masa Berlaku:* 5 Hari (s.d. {$formattedExp})\n\n" .
                "Silakan gunakan kode voucher di atas saat login ke WiFi hotspot *ND-Hotspot*.\n\n" .
                "Terima kasih atas kepercayaan & loyalitas Anda! 🙏\n\n" .
                "Salam hangat,\n*ND-Hotspot* 💡";

            $sent = $this->wa->sendMessage($phone, $message);
            if ($sent) {
                Log::info('RewardService: WhatsApp reward notice delivered', ['phone' => $phone]);
            } else {
                Log::warning('RewardService: WhatsApp gateway returned false', ['phone' => $phone]);
            }
        } catch (\Throwable $e) {
            Log::warning('RewardService: sendRewardWhatsAppNotification failed (non-blocking)', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
