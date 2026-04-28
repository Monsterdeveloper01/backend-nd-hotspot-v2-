<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\AppConfig;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Get maintenance status
        $maintenance = AppConfig::where('key', 'maintenance_mode')->first();
        $isMaintenance = $maintenance && $maintenance->value === '1';

        if ($isMaintenance) {
            // 2. Allow specific routes (Admin login, toggle, etc)
            $allowedPaths = [
                'api/login',
                'api/maintenance/*', 
                'api/log-visit', // Crucial: prevent tracking loop
            ];

            foreach ($allowedPaths as $path) {
                if ($request->is($path)) return $next($request);
            }

            // 3. Check for bypass token (karambia1686)
            $bypassToken = $request->header('X-Maintenance-Bypass') ?: $request->cookie('maintenance_bypass');
            
            if ($bypassToken === 'karambia1686') {
                return $next($request);
            }

            // 4. If admin is logged in (has valid token), also allow
            // This is optional but helpful
            if ($request->user('sanctum')) {
                return $next($request);
            }

            // 5. Block with 503 Service Unavailable
            return response()->json([
                'error' => 'maintenance_mode',
                'message' => 'Sistem sedang dalam pemeliharaan rutin. Silakan kembali beberapa saat lagi.'
            ], 503);
        }

        return $next($request);
    }
}
