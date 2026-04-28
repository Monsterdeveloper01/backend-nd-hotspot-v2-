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
        return response()->json([
            'maintenance_mode' => $mode ? $mode->value === '1' : false
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
            return response()->json([
                'success' => true,
                'token' => 'karambia1686' // Returning the token to be stored in frontend
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Password salah.'
        ], 401);
    }
}
