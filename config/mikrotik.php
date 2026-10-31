<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MikroTik RouterOS Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to the MikroTik RouterOS API.
    | Always accessed via config('mikrotik....') to ensure values are
    | preserved when config:cache is executed in production.
    |
    */

    'host'          => env('MIKROTIK_HOST', '101.255.208.150'),
    'fallback_host' => env('MIKROTIK_FALLBACK_HOST'),
    'user'          => env('MIKROTIK_USERNAME', 'admin'),
    'pass'          => env('MIKROTIK_PASSWORD', 'karambia1686'),
    'port'          => (int) env('MIKROTIK_PORT', 8728),
    'voucher_mode'  => env('VOUCHER_MODE', 'mikrotik'),
    'timeout'       => 8,
];

