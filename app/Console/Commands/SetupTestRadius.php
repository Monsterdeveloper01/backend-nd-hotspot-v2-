<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Models\VoucherPlan;
use App\Models\RadiusClient;

class SetupTestRadius extends Command
{
    protected $signature = 'app:setup-test-radius';
    protected $description = 'Setup test data for RADIUS testing';

    public function handle()
    {
        $plan = VoucherPlan::firstOrCreate(
            ['name' => 'Test Plan'],
            [
                'price' => 5000,
                'duration' => '1d',
                'upload_limit' => '1024',
                'download_limit' => '2048'
            ]
        );

        RadiusClient::updateOrCreate(
            ['ip_address' => '127.0.0.1'],
            ['name' => 'Localhost Tester', 'shared_secret' => 'testing123']
        );

        Voucher::updateOrCreate(
            ['code' => 'TESTING'],
            [
                'voucher_plan_id' => $plan->id,
                'status' => 'available',
                'customer_phone' => '08123456789',
                'price' => $plan->price
            ]
        );

        $this->info('Test Data Ready!');
        $this->info('Client: 127.0.0.1 | Secret: testing123');
        $this->info('Voucher: TESTING | Pass: TESTING');
    }
}
