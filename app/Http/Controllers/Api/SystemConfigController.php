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
        
        return response()->json([
            'maintenance_mode' => $mode ? $mode->value === '1' : false,
            'session_id' => $sessionId ? $sessionId->value : null
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

        if ($request->password === 'karambia1686') {
            $sessionId = AppConfig::where('key', 'maintenance_session_id')->first();
            return response()->json([
                'success' => true,
                'token' => $sessionId ? $sessionId->value : 'bypass'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Password salah.'
        ], 401);
    }
}
