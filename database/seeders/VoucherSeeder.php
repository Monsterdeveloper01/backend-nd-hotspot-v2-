<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Buat Voucher Plans
        $plans = [
            [
                'name' => 'Hemat 2 Jam',
                'duration' => '2h',
                'price' => 2000,
                'speed_limit' => '2M/2M',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Puas 1 Hari',
                'duration' => '1d',
                'price' => 5000,
                'speed_limit' => '5M/5M',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Sultan 30 Hari',
                'duration' => '30d',
                'price' => 100000,
                'speed_limit' => '10M/10M',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        foreach ($plans as $p) {
            $planId = DB::table('voucher_plans')->insertGetId($p);
            
            // 2. Buat 5 Voucher untuk masing-masing plan
            for ($i = 1; $i <= 5; $i++) {
                DB::table('vouchers')->insert([
                    'code' => 'TEST' . $planId . $i,
                    'voucher_plan_id' => $planId,
                    'price' => $p['price'], // Ambil harga dari plan
                    'status' => 'available',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }
}
