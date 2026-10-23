<?php

namespace App\Http\Controllers;

use App\Services\IncidentLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Authenticate admin user with brute-force protection and remember-me support.
     */
    public function login(Request $request)
    {
        $request->validate([
            'username'    => 'required|string',
            'password'    => 'required',
            'remember_me' => 'nullable|boolean',
        ]);

        $username = trim($request->input('username'));
        $throttleKey = 'login:' . Str::transliterate(Str::lower($username) . '|' . $request->ip());

        // 1. Check Brute-Force Rate Limiter (Max 5 attempts within 5 minutes)
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            // Report incident if critical threshold hit
            IncidentLoggerService::report(
                category: 'system',
                severity: 'critical',
                title: "Brute Force Terdeteksi pada Admin Login ({$username})",
                message: "IP {$request->ip()} telah gagal login 5 kali berturut-turut. Akses login terkunci sementara selama {$seconds} detik.",
                payload: [
                    'username'       => $username,
                    'ip'             => $request->ip(),
                    'user_agent'     => $request->userAgent(),
                    'locked_seconds' => $seconds,
                ],
                technicalDetails: "Throttle key: {$throttleKey}",
                actionType: 'security_alert'
            );

            return response()->json([
                'message'     => "Terlalu banyak percobaan login gagal. Demi keamanan sistem, akses diblokir sementara. Coba lagi dalam {$seconds} detik.",
                'locked'      => true,
                'retry_after' => $seconds,
            ], 429);
        }

        // 2. Attempt Authentication
        if (!Auth::attempt(['username' => $username, 'password' => $request->input('password')])) {
            RateLimiter::hit($throttleKey, 300); // 300 seconds lockout decay
            $remaining = RateLimiter::remaining($throttleKey, 5);

            $msg = $remaining > 0
                ? "Username atau password salah. Sisa kesempatan: {$remaining}x lagi sebelum akun dikunci."
                : "Username atau password salah. Batas percobaan habis. Akses dikunci sementara.";

            return response()->json([
                'message'            => $msg,
                'remaining_attempts' => $remaining,
            ], 401);
        }

        // 3. Login Success: Clear rate limiter
        RateLimiter::clear($throttleKey);

        $user = Auth::user();
        $remember = $request->boolean('remember_me', true);

        // Remember Me: 30 days token, Non-Remember: 12 hours token
        $expiresAt = $remember ? now()->addDays(30) : now()->addHours(12);

        // Prune old tokens older than 30 days for this user
        try {
            $user->tokens()->where('name', 'admin-token')->where('created_at', '<', now()->subDays(30))->delete();
        } catch (\Throwable $e) {
            // Non-blocking
        }

        $token = $user->createToken('admin-token', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'message'     => 'Login berhasil',
            'token'       => $token,
            'remember_me' => $remember,
            'expires_at'  => $expiresAt->toIso8601String(),
            'user'        => [
                'id'       => $user->id,
                'name'     => $user->name,
                'username' => $user->username,
                'email'    => $user->email,
            ],
        ]);
    }

    /**
     * Terminate current token session.
     */
    public function logout(Request $request)
    {
        if ($request->user() && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }

    /**
     * Fast session verification.
     */
    public function me(Request $request)
    {
        return response()->json([
            'valid' => true,
            'user'  => $request->user(),
        ]);
    }
}