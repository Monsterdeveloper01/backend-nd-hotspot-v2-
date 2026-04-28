<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VisitorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Log a new visitor hit
     */
    public function logVisit(Request $request)
    {
        // Simple throttle: don't log if same IP in last 5 minutes on same page
        $exists = VisitorLog::where('ip_address', $request->ip())
            ->where('page_visited', $request->input('page', 'home'))
            ->where('created_at', '>', now()->subMinutes(5))
            ->exists();

        if (!$exists) {
            VisitorLog::create([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'page_visited' => $request->input('page', 'home')
            ]);
        }

        return response()->json(['status' => 'logged']);
    }

    /**
     * Get peak hours data for the last 24 hours or 7 days
     */
    public function getPeakHours()
    {
        $stats = VisitorLog::select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('count(*) as count')
            )
            ->where('created_at', '>', now()->subDays(7))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        // Fill missing hours with 0
        $formatted = [];
        for ($i = 0; $i < 24; $i++) {
            $found = $stats->firstWhere('hour', $i);
            $formatted[] = [
                'hour' => str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
                'count' => $found ? $found->count : 0
            ];
        }

        return response()->json($formatted);
    }
}
