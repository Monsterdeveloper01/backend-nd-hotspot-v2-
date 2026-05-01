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
