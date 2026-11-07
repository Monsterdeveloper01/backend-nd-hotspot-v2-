<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', \App\Http\Middleware\CheckMaintenanceMode::class);
        
        // Jangan redirect unauthenticated guest ke route web 'login', tapi lempar AuthenticationException (401 JSON)
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withSchedule(function ($schedule) {
        // $schedule->command('billing:check')->dailyAt('08:00');
        // $schedule->command('voucher:cleanup')->everyTenMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (\Throwable $e) {
            // Abaikan error 404, validasi form biasa, auth unauthenticated
            if ($e instanceof \Illuminate\Validation\ValidationException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException ||
                $e instanceof \Illuminate\Auth\AuthenticationException) {
                return false;
            }

            // Abaikan RouteNotFoundException untuk route login (jika guest mengakses endpoint auth tanpa token)
            if ($e instanceof \Symfony\Component\Routing\Exception\RouteNotFoundException &&
                str_contains($e->getMessage(), 'login')) {
                return false;
            }

            // Abaikan warning PHP non-fatal entry skipped jika terjadi pada request scanner / bot
            if ($e instanceof \ErrorException && str_contains($e->getMessage(), 'array_flip')) {
                \Illuminate\Support\Facades\Log::warning($e->getMessage());
                return false;
            }

            \App\Services\IncidentLoggerService::logSystemException($e, 'Global Unhandled Exception');
        });
    })->create();
