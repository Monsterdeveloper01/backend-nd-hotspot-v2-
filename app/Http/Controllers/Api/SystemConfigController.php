<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppConfig;

class SystemConfigController extends Controller
{
    /**
     * Get maintenance status
     */
    public function getStatus()
    {
        $mode = AppConfig::where('key', 'maintenance_mode')->first();
        $sessionId = AppConfig::where('key', 'maintenance_session_id')->first();
        
        $pointMode = AppConfig::where('key', 'point_maintenance_mode')->first();
        $pointSessionId = AppConfig::where('key', 'point_maintenance_session_id')->first();

        return response()->json([
            'maintenance_mode' => $mode ? $mode->value === '1' : false,
            'session_id' => $sessionId ? $sessionId->value : null,
            'point_maintenance_mode' => $pointMode ? $pointMode->value === '1' : false,
            'point_session_id' => $pointSessionId ? $pointSessionId->value : null,
        ]);
    }

    /**
     * Toggle maintenance mode
     */
    public function toggleMaintenance(Request $request)
    {
        $request->validate([
            'active' => 'required|boolean'
        ]);

        $mode = AppConfig::updateOrCreate(
            ['key' => 'maintenance_mode'],
            ['value' => $request->active ? '1' : '0']
        );

        // If turning ON, generate a new session ID to invalidate old bypasses
        if ($request->active) {
            AppConfig::updateOrCreate(
                ['key' => 'maintenance_session_id'],
                ['value' => bin2hex(random_bytes(16))]
            );
        }

        return response()->json([
            'success' => true,
            'maintenance_mode' => $mode->value === '1',
            'message' => $request->active ? 'Sistem sekarang dalam mode pemeliharaan.' : 'Sistem kembali online.'
        ]);
    }

    /**
     * Verify bypass password
     */
    public function verifyBypass(Request $request)
    {
        $request->validate([
            'password' => 'required'
        ]);

        $throttleKey = 'maintenance-bypass:' . $request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak percobaan bypass gagal. Coba lagi dalam {$seconds} detik."
            ], 429);
        }

        $validPassword = env('MAINTENANCE_BYPASS_PASSWORD', 'karambia1686');

        if (hash_equals((string) $validPassword, (string) $request->password)) {
            \Illuminate\Support\Facades\RateLimiter::clear($throttleKey);
            $sessionId = AppConfig::where('key', 'maintenance_session_id')->first();
            return response()->json([
                'success' => true,
                'token' => $sessionId ? $sessionId->value : 'bypass'
            ]);
        }

        \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, 300);

        return response()->json([
            'success' => false,
            'message' => 'Password salah.'
        ], 401);
    }

    /**
     * Toggle ND-Points maintenance mode
     */
    public function togglePointMaintenance(Request $request)
    {
        $request->validate([
            'active' => 'required|boolean'
        ]);

        $mode = AppConfig::updateOrCreate(
            ['key' => 'point_maintenance_mode'],
            ['value' => $request->active ? '1' : '0']
        );

        // If turning ON, generate a new point session ID to invalidate old bypasses
        if ($request->active) {
            AppConfig::updateOrCreate(
                ['key' => 'point_maintenance_session_id'],
                ['value' => bin2hex(random_bytes(16))]
            );
        }

        return response()->json([
            'success' => true,
            'point_maintenance_mode' => $mode->value === '1',
            'message' => $request->active 
                ? 'Mode pemeliharaan khusus ND-Points aktif.' 
                : 'Layanan ND-Points kembali online untuk publik.'
        ]);
    }

    /**
     * Verify bypass password specifically for ND-Points
     */
    public function verifyPointBypass(Request $request)
    {
        $request->validate([
            'password' => 'required'
        ]);

        $throttleKey = 'point-maintenance-bypass:' . $request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'message' => "Terlalu banyak percobaan bypass gagal. Coba lagi dalam {$seconds} detik."
            ], 429);
        }

        $validPassword = env('MAINTENANCE_BYPASS_PASSWORD', 'karambia1686');

        if (hash_equals((string) $validPassword, (string) $request->password)) {
            \Illuminate\Support\Facades\RateLimiter::clear($throttleKey);
            $sessionId = AppConfig::where('key', 'point_maintenance_session_id')->first();
            return response()->json([
                'success' => true,
                'token' => $sessionId ? $sessionId->value : 'point_bypass_active'
            ]);
        }

        \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, 300);

        return response()->json([
            'success' => false,
            'message' => 'Password salah.'
        ], 401);
    }
}
