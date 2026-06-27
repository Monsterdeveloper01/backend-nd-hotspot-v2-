<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;
Schedule::command('app:send-daily-sales-report')->dailyAt('07:00');
Schedule::command('billing:check')->dailyAt('07:00');
Schedule::command('voucher:cleanup')->everyFiveMinutes();

// Radius Log & Session Management
Schedule::call(function () {
    // Hapus log RADIUS yang sudah lebih dari 7 hari
    \DB::table('radius_logs')->where('created_at', '<', now()->subDays(7))->delete();
    
    // Opsional: Hapus sesi RADIUS yang sudah tidak aktif lebih dari 10 hari
    \DB::table('radius_sessions')
        ->where('is_active', false)
        ->where('stopped_at', '<', now()->subDays(10))
        ->delete();
})->dailyAt('01:00');

Schedule::command('olt:sync')->everyMinute();
