<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventReward;
use App\Models\EventRewardRule;
use App\Models\EventTestState;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                "ℹ️ *Bantuan / Kendala Reward:*\n" .
                "Jika Anda mengalami kendala atau voucher reward belum dapat digunakan, silakan kirim bukti screenshot (SS) bahwa Anda sudah mencapai target ke nomor WhatsApp Admin ini untuk dibantu proses langsung.\n\n" .
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

    /**
     * Run isolated Loyalty Test Simulation.
     * 
     * Requirements:
     * - NO fake records in transactions table
     * - NO fake Midtrans payment
     * - NO change to sales reports or revenue
     * - Does NOT call MikroTik router UNLESS $options['use_real_mikrotik'] === true
     * - WhatsApp notice sent ONLY IF $options['send_whatsapp'] === true or LOYALTY_TEST_WHATSAPP=true
     * - Configurable expiry override for fast testing
     */
    public function runLoyaltyTest(Event $event, string $rawPhone, string $periodKey, float $amount, array $options = []): array
    {
        $phone = PhoneNumberService::normalize($rawPhone);
        if (!$phone) {
            return [
                'success' => false,
                'message' => 'Format nomor HP tidak valid. Gunakan format seperti 08123456789 atau 628123456789.',
            ];
        }

        $useRealMikrotik = !empty($options['use_real_mikrotik']);
        $expiryMinutes = isset($options['expiry_minutes']) && is_numeric($options['expiry_minutes']) ? (int) $options['expiry_minutes'] : null;
        $sendWhatsApp = !empty($options['send_whatsapp']) || env('LOYALTY_TEST_WHATSAPP', false) === true;

        // 1. Record isolated simulated state in event_test_states (zero impact on transactions table)
        $testState = EventTestState::updateOrCreate(
            [
                'event_id' => $event->id,
                'phone' => $phone,
                'period_key' => $periodKey,
            ],
            [
                'simulated_total_purchase' => $amount,
                'simulated_transaction_count' => max(1, (int) round($amount / 10000)),
                'use_real_mikrotik' => $useRealMikrotik,
            ]
        );

        $targetAmount = (float) $event->target_amount;
        $isTargetAchieved = $targetAmount > 0 ? ($amount >= $targetAmount) : true;
        $progressPercentage = $targetAmount > 0 ? round(($amount / $targetAmount) * 100, 1) : 100.0;
        $remainingAmount = max(0, $targetAmount - $amount);

        // Broadcast realtime update to admin and public loyalty
        $this->broadcastTestProgress($event, $phone, $periodKey, $amount);

        // Case A: Progress below target
        if (!$isTargetAchieved) {
            return [
                'success' => true,
                'is_target_achieved' => false,
                'phone' => $phone,
                'period_key' => $periodKey,
                'simulated_total_purchase' => $amount,
                'target_amount' => $targetAmount,
                'progress_percentage' => $progressPercentage,
                'remaining_amount' => $remainingAmount,
                'status' => 'progress_updated',
                'message' => 'Simulasi progress berhasil dicatat (' . $progressPercentage . '%). Target belum tercapai, voucher reward belum diterbitkan.',
                'reward' => null,
            ];
        }

        // Case B: Target reached! Check active reward rule
        $rule = EventRewardRule::where('event_id', $event->id)
            ->where('is_active', true)
            ->with('voucherPlan')
            ->first();

        if (!$rule) {
            return [
                'success' => true,
                'is_target_achieved' => true,
                'phone' => $phone,
                'period_key' => $periodKey,
                'simulated_total_purchase' => $amount,
                'target_amount' => $targetAmount,
                'progress_percentage' => $progressPercentage,
                'remaining_amount' => 0,
                'status' => 'target_reached_no_rule',
                'message' => 'Target tercapai! Namun belum ada Reward Rule aktif yang dikonfigurasi untuk event ini.',
                'reward' => null,
            ];
        }

        // Check idempotency: does a reward already exist for this (event_id, phone, period_key, rule_id)?
        $existing = EventReward::where('event_id', $event->id)
            ->where('phone', $phone)
            ->where('period_key', $periodKey)
            ->where('event_reward_rule_id', $rule->id)
            ->with('voucher')
            ->first();

        if ($existing && in_array($existing->status, ['issued', 'used'])) {
            return [
                'success' => true,
                'is_target_achieved' => true,
                'is_idempotent_duplicate' => true,
                'phone' => $phone,
                'period_key' => $periodKey,
                'simulated_total_purchase' => $amount,
                'target_amount' => $targetAmount,
                'progress_percentage' => $progressPercentage,
                'remaining_amount' => 0,
                'status' => 'already_rewarded',
                'message' => 'IDEMPOTENCY TERVERIFIKASI: Customer sudah memiliki reward sebelumnya. Sistem menolak membuat reward/voucher ganda.',
                'reward' => [
                    'id' => $existing->id,
                    'status' => $existing->status,
                    'rule_name' => $rule->name,
                    'voucher_code' => $existing->voucher?->code,
                    'issued_at' => $existing->issued_at?->format('d M Y, H:i'),
                    'expires_at' => $existing->expires_at?->format('d M Y, H:i'),
                    'is_test' => (bool) $existing->is_test,
                ],
            ];
        }

        // Create or reuse reward record atomically
        if ($existing) {
            $reward = $existing;
            $reward->update([
                'status' => 'processing',
                'is_test' => true,
                'error_message' => null,
            ]);
        } else {
            try {
                $reward = EventReward::create([
                    'event_id' => $event->id,
                    'event_reward_rule_id' => $rule->id,
                    'phone' => $phone,
                    'period_key' => $periodKey,
                    'reward_type' => 'voucher',
                    'reward_value' => $rule->voucherPlan?->name ?? 'Reward Voucher (Test)',
                    'status' => 'processing',
                    'is_test' => true,
                    'granted_at' => Carbon::now(),
                ]);
            } catch (QueryException $e) {
                $reward = EventReward::where('event_id', $event->id)
                    ->where('phone', $phone)
                    ->where('period_key', $periodKey)
                    ->where('event_reward_rule_id', $rule->id)
                    ->first();
            }
        }

        // Generate voucher code
        $plan = $rule->voucherPlan;
        $issuedAt = Carbon::now();

        // Calculate expiry: custom test expiry (e.g. 1 minute) or standard 5 days
        if ($expiryMinutes && $expiryMinutes > 0) {
            $expiresAt = (clone $issuedAt)->addMinutes($expiryMinutes);
        } else {
            $expiresAt = (clone $issuedAt)->addDays(5);
        }

        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $voucherCode = substr(str_shuffle(str_repeat($chars, 6)), 0, 6);
        } while (Voucher::where('code', $voucherCode)->exists());

        // Handle MikroTik based on Level A (Simulation) vs Level B (Integration)
        $mikrotikId = null;
        $mikrotikSuccess = false;
        $mikrotikMode = 'mock';

        if ($useRealMikrotik) {
            // Level B: Real MikroTik Integration Test
            $mikrotikMode = 'real_mikrotik';
            try {
                $profileName = $plan ? ($plan->mikrotik_profile ?: $plan->name) : 'default';
                $limitUptime = $expiryMinutes ? "{$expiryMinutes}m" : '5d';

                $mikrotikResult = $this->mikrotik->createUser([
                    'username' => $voucherCode,
                    'password' => '',
                    'profile' => $profileName,
                    'comment' => 'TEST REWARD: ' . substr($rule->name, 0, 20) . ' Exp:' . $expiresAt->format('d/m/Y H:i'),
                    'limit_uptime' => $limitUptime,
                ]);

                if (is_array($mikrotikResult) && (isset($mikrotikResult[0]['.id']) || !isset($mikrotikResult['!trap']))) {
                    $mikrotikId = $mikrotikResult[0]['.id'] ?? 'created';
                    $mikrotikSuccess = true;
                } else {
                    $reward->update([
                        'status' => 'failed',
                        'error_message' => 'MikroTik real test trap: ' . json_encode($mikrotikResult),
                    ]);
                    return [
                        'success' => false,
                        'message' => 'Gagal membuat user di router MikroTik nyata.',
                        'error' => $mikrotikResult,
                    ];
                }
            } catch (\Throwable $e) {
                $reward->update([
                    'status' => 'failed',
                    'error_message' => 'MikroTik real test exception: ' . $e->getMessage(),
                ]);
                return [
                    'success' => false,
                    'message' => 'Exception koneksi MikroTik: ' . $e->getMessage(),
                ];
            }
        } else {
            // Level A: Simulation Test (Mock MikroTik)
            $mikrotikMode = 'simulation_mock';
            $mikrotikId = 'SIM-MTK-' . strtoupper(Str::random(6));
            $mikrotikSuccess = true;
        }

        // Save Voucher with is_test = true and source = 'reward'
        $voucher = Voucher::create([
            'voucher_plan_id' => $plan?->id,
            'code' => $voucherCode,
            'price' => 0.00,
            'status' => 'sold',
            'source' => 'reward',
            'is_test' => true,
            'customer_phone' => $phone,
            'mikrotik_id' => $mikrotikId,
            'expires_at' => $expiresAt,
        ]);

        // Transition reward status to issued
        $reward->update([
            'status' => 'issued',
            'voucher_id' => $voucher->id,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'error_message' => null,
        ]);

        // Realtime broadcast to Socket.IO
        $this->broadcastRewardIssued($event, $reward, $voucher, $phone, $periodKey);

        // Optional WhatsApp Notification
        if ($sendWhatsApp) {
            $this->sendRewardWhatsAppNotification($rule, $voucherCode, $phone, $expiresAt);
        }

        return [
            'success' => true,
            'is_target_achieved' => true,
            'is_idempotent_duplicate' => false,
            'phone' => $phone,
            'period_key' => $periodKey,
            'simulated_total_purchase' => $amount,
            'target_amount' => $targetAmount,
            'progress_percentage' => $progressPercentage,
            'remaining_amount' => 0,
            'status' => 'reward_issued',
            'mode' => $mikrotikMode,
            'message' => '🎉 Reward berhasil diterbitkan! ' . ($useRealMikrotik ? '(Tercatat di MikroTik Router Nyata)' : '(Mode Simulasi Aman - Router MikroTik Tidak Disentuh)'),
            'reward' => [
                'id' => $reward->id,
                'status' => 'issued',
                'rule_name' => $rule->name,
                'voucher_code' => $voucherCode,
                'issued_at' => $issuedAt->format('d M Y, H:i'),
                'expires_at' => $expiresAt->format('d M Y, H:i'),
                'expires_at_formatted' => $expiresAt->translatedFormat('d F Y, H:i'),
                'is_test' => true,
                'mikrotik_mode' => $mikrotikMode,
                'mikrotik_id' => $mikrotikId,
            ],
        ];
    }

    /**
     * Reset loyalty test data safely without touching production records.
     * 
     * Cleans up ONLY:
     * - event_test_states
     * - event_rewards WHERE is_test = true
     * - vouchers WHERE is_test = true
     * - If real MikroTik was used for a test voucher, deletes the test user from router
     */
    public function resetLoyaltyTest(Event $event, ?string $rawPhone = null): array
    {
        $normalizedPhone = $rawPhone ? PhoneNumberService::normalize($rawPhone) : null;

        // 1. Find test rewards
        $rewardsQuery = EventReward::where('event_id', $event->id)->where('is_test', true);
        if ($normalizedPhone) {
            $rewardsQuery->where('phone', $normalizedPhone);
        }
        $testRewards = $rewardsQuery->with('voucher')->get();

        $cleanedVouchersCount = 0;
        $cleanedMtkUsersCount = 0;

        foreach ($testRewards as $reward) {
            if ($reward->voucher) {
                // If real MikroTik was used, clean it up
                if (!str_starts_with($reward->voucher->mikrotik_id ?? '', 'SIM-MTK-')) {
                    try {
                        $this->mikrotik->removeHotspotUser($reward->voucher->code);
                        $cleanedMtkUsersCount++;
                    } catch (\Throwable $e) {
                        Log::warning('ResetLoyaltyTest: Failed to remove test user from MikroTik', ['code' => $reward->voucher->code]);
                    }
                }
                $reward->voucher->delete();
                $cleanedVouchersCount++;
            }
            $reward->delete();
        }

        // Also clean any dangling test vouchers with is_test = true for this phone
        $orphanVouchersQuery = Voucher::where('is_test', true);
        if ($normalizedPhone) {
            $orphanVouchersQuery->where('customer_phone', $normalizedPhone);
        }
        $cleanedVouchersCount += $orphanVouchersQuery->delete();

        // 2. Delete test states
        $statesQuery = EventTestState::where('event_id', $event->id);
        if ($normalizedPhone) {
            $statesQuery->where('phone', $normalizedPhone);
        }
        $cleanedStatesCount = $statesQuery->delete();

        // Broadcast update via Socket.IO so public and admin UI immediately refresh
        if ($normalizedPhone) {
            $this->broadcastTestProgress($event, $normalizedPhone, Carbon::now()->format('Y-m'), 0);
        }

        return [
            'success' => true,
            'message' => 'Data test loyalty berhasil direset. Data transaksi dan voucher produksi 100% aman.',
            'stats' => [
                'cleaned_states' => $cleanedStatesCount,
                'cleaned_rewards' => $testRewards->count(),
                'cleaned_vouchers' => $cleanedVouchersCount,
                'cleaned_mikrotik_users' => $cleanedMtkUsersCount,
            ],
        ];
    }

    /**
     * Broadcast simulated test progress to Socket.IO.
     */
    protected function broadcastTestProgress(Event $event, string $phone, string $periodKey, float $amount): void
    {
        try {
            $waGatewayUrl = env('WHATSAPP_GATEWAY_URL', 'http://localhost:5000');
            Http::timeout(1)->asJson()->post("{$waGatewayUrl}/broadcast-analytics", [
                'event_id' => $event->id,
                'phone' => $phone,
                'period_key' => $periodKey,
                'amount' => $amount,
                'is_test' => true,
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            // Non-blocking
        }
    }
}
