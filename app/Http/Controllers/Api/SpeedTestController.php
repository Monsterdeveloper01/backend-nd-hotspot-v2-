<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SpeedTestController extends Controller
{
    /**
     * Download Test
     * Returns a large chunk of random data
     */
    public function download(Request $request)
    {
        // Size in MB, default 5MB
        $size = $request->get('size', 5);
        if ($size > 20) $size = 20; // Limit to 20MB
        
        $data = str_repeat('0', $size * 1024 * 1024);
        
        return response($data)
            ->header('Content-Type', 'application/octet-stream')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Upload Test
     * Accepts any data and discards it
     */
    public function upload(Request $request)
    {
        // Just return success
        return response()->json([
            'success' => true,
            'received_bytes' => strlen($request->getContent())
        ]);
    }

    /**
     * Ping Test
     * Minimal response time
     */
    public function ping()
    {
        return response()->json(['status' => 'pong', 'time' => now()->toDateTimeString()]);
    }
}
