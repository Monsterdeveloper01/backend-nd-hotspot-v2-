<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectDirectApiAccess
{
    /**
     * Handle an incoming request.
     * Prevents users or hackers from typing API endpoints directly in browser address bar.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Webhook & Non-Safe methods (POST, PUT, DELETE) are NEVER direct browser URL navigations
        if (!$request->isMethodSafe()) {
            return $next($request);
        }

        // 2. Explicitly bypass payment callbacks and webhook endpoints
        if ($request->is('*midtrans*') || $request->is('*callback*') || $request->is('*webhook*')) {
            return $next($request);
        }

        // 3. Detect direct browser navigation (when typed into URL address bar)
        // Desktop & Mobile modern browsers send Sec-Fetch-Mode: navigate & Sec-Fetch-Dest: document
        $isDirectBrowserNav = $request->header('Sec-Fetch-Mode') === 'navigate'
            || $request->header('Sec-Fetch-Dest') === 'document';

        // Fallback for older browsers: Accept header explicitly requesting text/html (page visit) and NOT xhr/ajax
        if (!$isDirectBrowserNav && !$request->ajax() && !$request->expectsJson()) {
            $accept = $request->header('Accept', '');
            if (str_starts_with($accept, 'text/html')) {
                $isDirectBrowserNav = true;
            }
        }

        if ($isDirectBrowserNav) {
            $frontendUrl = env('FRONTEND_URL', 'https://nd-hostpot.net');
            return redirect()->to($frontendUrl);
        }

        $response = $next($request);

        // Tambahkan header proteksi keamanan
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
