<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeepBusinessAnalyticsService;
use Illuminate\Http\Request;

class DeepAnalyticsController extends Controller
{
    protected DeepBusinessAnalyticsService $analyticsService;

    public function __construct(DeepBusinessAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get Deep Business Analytics Data
     */
    public function index(Request $request)
    {
        $days = (int) $request->input('days', 30);
        if ($days < 7 || $days > 180) {
            $days = 30;
        }

        $data = $this->analyticsService->getAnalytics($days);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Force Re-generate AI Insights
     */
    public function refreshAi(Request $request)
    {
        $days = (int) $request->input('days', 30);
        $fullAnalytics = $this->analyticsService->getAnalytics($days);
        $aiInsights = $this->analyticsService->generateAiInsights($fullAnalytics);

        return response()->json([
            'success' => true,
            'ai_insights' => $aiInsights,
        ]);
    }
}
