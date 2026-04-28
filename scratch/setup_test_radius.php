<?php

use App\Models\Voucher;
use App\Models\VoucherPlan;
use App\Models\RadiusClient;

// Ensure we have a plan
$plan = VoucherPlan::firstOrCreate(
    ['name' => 'Test Plan'],
    [
        'price' => 5000,
        'duration' => '1d',
        'upload_limit' => '1024',
        'download_limit' => '2048'
    ]
);

// Create a test client for 127.0.0.1
RadiusClient::updateOrCreate(
    ['ip_address' => '127.0.0.1'],
    ['name' => 'Localhost Tester', 'shared_secret' => 'testing123']
);

// Create a test voucher
$code = 'TESTING';
Voucher::updateOrCreate(
    ['code' => $code],
    [
        'voucher_plan_id' => $plan->id,
        'status' => 'available',
        'customer_phone' => '08123456789'
    ]
);

echo "SUCCESS:\n";
echo "1. Radius Client added: 127.0.0.1 (Secret: testing123)\n";
echo "2. Voucher created: $code\n";
echo "3. Password for testing: $code (or '1' if using default)\n";
