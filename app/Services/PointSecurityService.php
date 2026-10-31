<?php

namespace App\Services;

use App\Models\PointAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PointSecurityService
{
    /**
     * Request a 4-digit OTP code sent via WhatsApp for PIN setup or reset.
     *
     * @param string $rawPhone
     * @param string $action 'setup_pin' or 'reset_pin'
     * @return array
     */
    public static function requestOtp(string $rawPhone, string $action = 'setup_pin'): array
    {
        $phone = PhoneNumberService::normalize($rawPhone);
        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'Format nomor WhatsApp tidak valid.',
            ];
        }

        // 1. Throttling: Max 1 OTP request per 60 seconds per phone
        $throttleKey = "point_otp_throttle:{$phone}";
        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return [
                'success' => false,
                'message' => "Mohon tunggu {$seconds} detik sebelum meminta kode OTP kembali.",
                'retry_after' => $seconds,
            ];
        }

        // 2. Max 5 OTP requests per hour to prevent spamming WhatsApp Gateway
        $hourlyKey = "point_otp_hourly:{$phone}";
        if (RateLimiter::tooManyAttempts($hourlyKey, 5)) {
            $seconds = RateLimiter::availableIn($hourlyKey);
            $minutes = (int) ceil($seconds / 60);
            return [
                'success' => false,
                'message' => "Terlalu banyak permintaan OTP. Silakan coba lagi dalam {$minutes} menit.",
                'retry_after' => $seconds,
            ];
        }

        // 3. Generate 4-digit numeric OTP
        $otp = sprintf('%04d', random_int(1000, 9999));

        // 4. Cache OTP for 5 minutes
        $cacheKey = "point_otp:{$action}:{$phone}";
        Cache::put($cacheKey, $otp, now()->addMinutes(5));

        RateLimiter::hit($throttleKey, 60);
        RateLimiter::hit($hourlyKey, 3600);

        // 5. Send message via WhatsApp
        $actionText = $action === 'reset_pin' ? 'mengatur ulang PIN' : 'mengatur PIN keamanan';
        $message = "🔐 *KODE KEAMANAN ND-POINTS*\n\n"
            . "Kode OTP Anda: *{$otp}*\n\n"
            . "Gunakan kode ini untuk {$actionText} ND-Points Anda di portal ND-Hotspot.\n"
            . "⚠️ *JANGAN BERIKAN KODE INI KEPADA SIAPAPUN*, termasuk pihak admin ND-Hotspot.\n\n"
            . "_Kode ini hanya berlaku selama 5 menit._";

        try {
            $waService = app(WhatsAppService::class);
            $sent = $waService->sendMessage($phone, $message, 'OTP ND-Points');
            if (!$sent) {
                Log::warning("PointSecurityService: Failed to dispatch WhatsApp OTP to {$phone}");
            }
        } catch (\Throwable $e) {
            Log::error("PointSecurityService: Exception sending WhatsApp OTP: " . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => 'Kode OTP berhasil dikirim ke nomor WhatsApp Anda.',
            'expires_in' => 300,
        ];
    }

    /**
     * Verify OTP code and setup 6-digit PIN.
     *
     * @param string $rawPhone
     * @param string $otp
     * @param string $pin
     * @param string $action
     * @return array
     */
    public static function setupPinWithOtp(string $rawPhone, string $otp, string $pin, string $action = 'setup_pin'): array
    {
        $phone = PhoneNumberService::normalize($rawPhone);
        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'Format nomor WhatsApp tidak valid.',
            ];
        }

        // Validate 4 to 6 digits PIN
        if (!preg_match('/^[0-9]{4,6}$/', $pin)) {
            return [
                'success' => false,
                'message' => 'PIN harus terdiri dari 4 sampai 6 angka.',
            ];
        }

        // Verify OTP from cache
        $cacheKey = "point_otp:{$action}:{$phone}";
        $cachedOtp = Cache::get($cacheKey);

        if (!$cachedOtp || $cachedOtp !== trim($otp)) {
            return [
                'success' => false,
                'message' => 'Kode OTP salah atau sudah kadaluarsa. Silakan minta kode baru.',
            ];
        }

        // Invalidate OTP immediately upon success
        Cache::forget($cacheKey);

        // Find or create PointAccount
        $account = PointAccount::firstOrCreate(
            ['phone' => $phone],
            [
                'balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_spent' => 0,
                'status' => 'active',
            ]
        );

        // Set new PIN
        $account->setPin($pin);

        return [
            'success' => true,
            'message' => 'PIN keamanan ND-Points berhasil disimpan.',
        ];
    }

    /**
     * Verify customer PIN against account hash with rate limiting & lockout.
     *
     * @param string $rawPhone
     * @param string $pin
     * @return array
     */
    public static function verifyPin(string $rawPhone, string $pin): array
    {
        $phone = PhoneNumberService::normalize($rawPhone);
        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'Format nomor WhatsApp tidak valid.',
            ];
        }

        $account = PointAccount::where('phone', $phone)->first();
        if (!$account) {
            return [
                'success' => false,
                'has_account' => false,
                'message' => 'Akun ND-Points belum terdaftar untuk nomor ini.',
            ];
        }

        if (!$account->hasPin()) {
            return [
                'success' => false,
                'has_pin' => false,
                'message' => 'Nomor ini belum memiliki PIN. Silakan buat PIN terlebih dahulu.',
            ];
        }

        if ($account->isLocked()) {
            $remaining = $account->getLockSecondsRemaining();
            $minutes = (int) ceil($remaining / 60);
            return [
                'success' => false,
                'locked' => true,
                'retry_after' => $remaining,
                'message' => "Akun terkunci sementara karena 5x salah PIN. Silakan tunggu {$minutes} menit atau gunakan menu Lupa PIN.",
            ];
        }

        $isValid = $account->verifyPin($pin);
        if (!$isValid) {
            $account->refresh();
            if ($account->isLocked()) {
                IncidentLoggerService::report(
                    'point_fraud',
                    'critical',
                    'AKUN ND-POINTS TERKUNCI (POTENSI BRUTE FORCE)',
                    "Nomor {$phone} telah dikunci selama 15 menit akibat 5 kali salah memasukkan PIN berturut-turut.",
                    ['phone' => $phone, 'failed_attempts' => $account->failed_pin_attempts]
                );

                return [
                    'success' => false,
                    'locked' => true,
                    'message' => 'PIN salah 5 kali berturut-turut. Akun dikunci sementara selama 15 menit.',
                ];
            }

            if ($account->failed_pin_attempts >= 3) {
                IncidentLoggerService::report(
                    'point_fraud',
                    'warning',
                    'Percobaan Salah PIN ND-Points Berulang',
                    "Nomor {$phone} telah salah memasukkan PIN sebanyak {$account->failed_pin_attempts} kali.",
                    ['phone' => $phone, 'failed_attempts' => $account->failed_pin_attempts]
                );
            }

            $attemptsLeft = max(0, 5 - (int) $account->failed_pin_attempts);
            return [
                'success' => false,
                'attempts_left' => $attemptsLeft,
                'message' => "PIN yang Anda masukkan salah. Sisa percobaan: {$attemptsLeft} kali.",
            ];
        }

        return [
            'success' => true,
            'message' => 'PIN terverifikasi.',
        ];
    }

    /**
     * Get security status of an account (has PIN, is locked, balance).
     *
     * @param string $rawPhone
     * @return array
     */
    public static function getAccountStatus(string $rawPhone): array
    {
        $phone = PhoneNumberService::normalize($rawPhone);
        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'Nomor WhatsApp tidak valid.',
            ];
        }

        $account = PointAccount::where('phone', $phone)->first();
        if (!$account) {
            return [
                'success' => true,
                'exists' => false,
                'has_pin' => false,
                'is_locked' => false,
                'balance' => 0,
            ];
        }

        return [
            'success' => true,
            'exists' => true,
            'has_pin' => $account->hasPin(),
            'is_locked' => $account->isLocked(),
            'lock_seconds_remaining' => $account->getLockSecondsRemaining(),
            'balance' => (int) $account->balance,
            'lifetime_earned' => (int) $account->lifetime_earned,
        ];
    }
}

