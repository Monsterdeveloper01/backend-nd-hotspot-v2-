<?php

namespace App\Services;

use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class VoucherSyncService
{
    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Convert seconds to RouterOS limit-uptime string format (e.g. 20d4h12m)
     */
    public function secondsToMikrotikUptime(int $seconds): string
    {
        if ($seconds <= 0) return '1s';

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        if ($secs > 0 || empty($parts)) $parts[] = "{$secs}s";

        return implode('', $parts);
    }

    /**
     * Format seconds to Indonesian human-readable string
     */
    public function secondsToHuman(int $seconds): string
    {
        if ($seconds <= 0) return '0 detik';

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        $parts = [];
        if ($days > 0) $parts[] = "{$days} hari";
        if ($hours > 0) $parts[] = "{$hours} jam";
        if ($minutes > 0) $parts[] = "{$minutes} menit";

        return !empty($parts) ? implode(' ', $parts) : "{$seconds} detik";
    }

    /**
     * Synchronize valid, unexpired vouchers to MikroTik with dynamic duration adjustment.
     *
     * @param array $options [
     *   'dry_run' => bool,
     *   'only_rewards' => bool,
     *   'include_stock' => bool,
     *   'force_update' => bool,
     * ]
     * @return array
     */
    public function sync(array $options = []): array
    {
        $dryRun = $options['dry_run'] ?? false;
        $onlyRewards = $options['only_rewards'] ?? false;
        $includeStock = $options['include_stock'] ?? true;
        $forceUpdate = $options['force_update'] ?? false;

        $now = Carbon::now();

        // 1. Check MikroTik Connection
        if (!$dryRun) {
            if (!$this->mikrotik->connect()) {
                return [
                    'success' => false,
                    'message' => 'Gagal terhubung ke router MikroTik. Pastikan router online dan IP/port API benar.',
                    'stats' => [],
                    'details' => [],
                ];
            }
        }

        // 2. Fetch existing MikroTik users for lookup
        $existingUsers = [];
        if (!$dryRun) {
            $mikrotikUsers = $this->mikrotik->getAllHotspotUsers();
            foreach ($mikrotikUsers as $u) {
                if (!empty($u['name'])) {
                    $existingUsers[strtolower(trim($u['name']))] = $u;
                }
            }
        }

        // 3. Query Target Vouchers
        $allowedStatuses = ['sold', 'used'];
        if ($includeStock) {
            $allowedStatuses[] = 'available';
        }

        $query = Voucher::with('plan')
            ->whereIn('status', $allowedStatuses)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', $now);
            });

        if ($onlyRewards) {
            $query->where('source', 'reward');
        }

        $vouchers = $query->orderBy('created_at', 'desc')->get();

        $stats = [
            'total_scanned' => $vouchers->count(),
            'already_in_mikrotik' => 0,
            'synced_never_used' => 0,       // Full duration injected
            'synced_active_remaining' => 0, // Adjusted remaining duration injected
            'skipped_expired' => 0,
            'failed' => 0,
            'updated' => 0,
        ];

        $details = [];

        // Begin batch mode for performance
        if (!$dryRun) {
            $this->mikrotik->beginBatch();
        }

        try {
            foreach ($vouchers as $voucher) {
                $code = strtoupper(trim($voucher->code));
                $codeLower = strtolower($code);
                $plan = $voucher->plan;
                $profileName = $plan ? ($plan->mikrotik_profile ?: $plan->name) : 'default';
                $planDuration = $plan ? ($plan->duration ?: '0') : '0';

                // Check if user already exists in MikroTik
                $userExists = isset($existingUsers[$codeLower]);

                // Determine usage and remaining time
                $isNeverUsed = ($voucher->status !== 'used' && $voucher->used_at === null);
                $calculatedUptime = '0';
                $remainingHuman = '';
                $actionType = '';

                if ($isNeverUsed) {
                    // CASE A: Belum pernah dipakai -> Pakai limit uptime penuh dari paket
                    $calculatedUptime = $planDuration;
                    $remainingHuman = "Penuh ({$planDuration})";
                    $actionType = 'never_used';
                } else {
                    // CASE B: Sedang aktif / sudah pernah dipakai -> Hitung sisa waktu persis
                    $expiresAt = $voucher->expires_at;

                    // Fallback jika used_at ada tapi expires_at null
                    if (!$expiresAt && $voucher->used_at && $planDuration !== '0') {
                        $expiresAt = clone $voucher->used_at;
                        if (preg_match('/(\d+)d/', $planDuration, $m)) $expiresAt->addDays((int)$m[1]);
                        if (preg_match('/(\d+)h/', $planDuration, $m)) $expiresAt->addHours((int)$m[1]);
                        if (preg_match('/(\d+)m/', $planDuration, $m)) $expiresAt->addMinutes((int)$m[1]);
                    }

                    if ($expiresAt && $expiresAt->lte($now)) {
                        // Sudah lewat batas waktu
                        $stats['skipped_expired']++;
                        if (!$dryRun && $voucher->status !== 'expired') {
                            $voucher->update(['status' => 'expired']);
                        }
                        $details[] = [
                            'code' => $code,
                            'plan' => $plan->name ?? '-',
                            'status' => 'expired',
                            'action' => 'skipped',
                            'reason' => 'Sudah melewati masa aktif',
                        ];
                        continue;
                    }

                    $remainingSeconds = $expiresAt ? (int) $now->diffInSeconds($expiresAt, false) : 0;
                    if ($remainingSeconds <= 0) {
                        $stats['skipped_expired']++;
                        continue;
                    }

                    $calculatedUptime = $this->secondsToMikrotikUptime($remainingSeconds);
                    $remainingHuman = "Sisa " . $this->secondsToHuman($remainingSeconds);
                    $actionType = 'active_remaining';
                }

                // Comment label for MikroTik
                $commentPrefix = $voucher->source === 'reward' ? 'REWARD-SYNC' : 'ND-SYNC';
                $comment = "{$commentPrefix}: {$remainingHuman}";
                if ($voucher->customer_phone) {
                    $comment .= " (" . substr($voucher->customer_phone, -4) . ")";
                }

                if ($userExists) {
                    $stats['already_in_mikrotik']++;
                    
                    if ($forceUpdate && !$dryRun) {
                        try {
                            $mtId = $existingUsers[$codeLower]['.id'] ?? null;
                            if ($mtId) {
                                $this->mikrotik->comm('/ip/hotspot/user/set', [
                                    '.id' => $mtId,
                                    'profile' => $profileName,
                                    'limit-uptime' => $calculatedUptime,
                                    'comment' => $comment . ' [Updated]',
                                ]);
                                $stats['updated']++;
                            }
                        } catch (\Throwable $e) {
                            Log::error("VoucherSync: Failed to update user {$code}", ['error' => $e->getMessage()]);
                        }
                    }

                    $details[] = [
                        'code' => $code,
                        'plan' => $plan->name ?? '-',
                        'source' => $voucher->source,
                        'type' => $actionType,
                        'uptime' => $calculatedUptime,
                        'human_time' => $remainingHuman,
                        'status' => 'already_exists',
                        'action' => $forceUpdate ? 'updated' : 'skipped',
                    ];
                    continue;
                }

                // Inject new user into MikroTik
                if (!$dryRun) {
                    try {
                        $createResult = $this->mikrotik->createUser([
                            'username' => $code,
                            'password' => '',
                            'profile' => $profileName,
                            'limit_uptime' => $calculatedUptime,
                            'comment' => $comment,
                        ]);

                        if (is_array($createResult) && (isset($createResult[0]['.id']) || !isset($createResult['!trap']))) {
                            $newId = $createResult[0]['.id'] ?? 'created';
                            $voucher->update(['mikrotik_id' => $newId]);

                            if ($actionType === 'never_used') {
                                $stats['synced_never_used']++;
                            } else {
                                $stats['synced_active_remaining']++;
                            }

                            $details[] = [
                                'code' => $code,
                                'plan' => $plan->name ?? '-',
                                'source' => $voucher->source,
                                'type' => $actionType,
                                'uptime' => $calculatedUptime,
                                'human_time' => $remainingHuman,
                                'status' => 'success',
                                'action' => 'created',
                            ];
                        } else {
                            $stats['failed']++;
                            $details[] = [
                                'code' => $code,
                                'status' => 'failed',
                                'reason' => 'MikroTik trap: ' . json_encode($createResult),
                            ];
                        }
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        Log::error("VoucherSync: Exception creating {$code}", ['error' => $e->getMessage()]);
                        $details[] = [
                            'code' => $code,
                            'status' => 'failed',
                            'reason' => $e->getMessage(),
                        ];
                    }
                } else {
                    // Dry run simulation
                    if ($actionType === 'never_used') {
                        $stats['synced_never_used']++;
                    } else {
                        $stats['synced_active_remaining']++;
                    }
                    $details[] = [
                        'code' => $code,
                        'plan' => $plan->name ?? '-',
                        'source' => $voucher->source,
                        'type' => $actionType,
                        'uptime' => $calculatedUptime,
                        'human_time' => $remainingHuman,
                        'status' => 'simulated',
                        'action' => 'would_create',
                    ];
                }
            }
        } finally {
            if (!$dryRun) {
                $this->mikrotik->endBatch();
            }
        }

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'message' => $dryRun 
                ? 'Simulasi sinkronisasi selesai diperiksa.' 
                : 'Sinkronisasi voucher ke MikroTik berhasil diselesaikan.',
            'stats' => $stats,
            'details' => $details,
        ];
    }
}
