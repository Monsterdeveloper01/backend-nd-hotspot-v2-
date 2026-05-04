<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Voucher;
use App\Models\VoucherPlan;
use Carbon\Carbon;

class ImportLegacyVouchers extends Command
{
    protected $signature = 'voucher:import-legacy';
    protected $description = 'Import legacy vouchers from ND-Hotspot v1 text data';

    public function handle()
    {
        $data = [
            ['code' => '8SQWEP', 'profile' => '30-Hari-Online', 'expires' => '2026-05-04 11:45:01'],
            ['code' => 'NFQE4X', 'profile' => '30-Hari-Online', 'expires' => '2026-05-05 11:35:02'],
            ['code' => '6F42HX', 'profile' => '30-Hari-Online', 'expires' => '2026-05-06 09:25:02'],
            ['code' => '3KNWMF', 'profile' => '30-Hari-Online', 'expires' => '2026-05-07 15:05:02'],
            ['code' => 'MT8K2U', 'profile' => '30-Hari-Online', 'expires' => '2026-05-08 13:45:01'],
            ['code' => '63JF7N', 'profile' => '30-Hari-Online', 'expires' => '2026-05-08 21:05:02'],
            ['code' => 'ZP2JCF', 'profile' => '30-Hari-Online', 'expires' => '2026-05-14 13:15:02'],
            ['code' => '734HYQ', 'profile' => '30-Hari-Online', 'expires' => '2026-05-15 11:05:01'],
            ['code' => 'P79XW2', 'profile' => '30-Hari-Online', 'expires' => '2026-05-16 16:45:01'],
            ['code' => 'J8EUR5', 'profile' => '30-Hari-Online', 'expires' => '2026-05-19 18:00:02'],
            ['code' => 'DQR8ZM', 'profile' => '30-Hari-Online', 'expires' => '2026-05-20 12:50:02'],
            ['code' => 'MCJ9WA', 'profile' => '30-Hari-Online', 'expires' => '2026-05-24 14:15:01'],
            ['code' => '3GHAF7', 'profile' => '30-Hari-Online', 'expires' => '2026-05-26 02:45:01'],
            ['code' => 'C7JU6Q', 'profile' => '30-Hari-Online', 'expires' => '2026-05-26 19:10:02'],
            ['code' => 'TDPX2Y', 'profile' => '30-Hari-Online', 'expires' => '2026-05-28 10:30:02'],
            ['code' => 'YK6FW9', 'profile' => '7-Hari-Online',  'expires' => '2026-05-06 09:45:01'],
            ['code' => '5NH3CD', 'profile' => '30-Hari-Online', 'expires' => '2026-05-30 11:30:02'],
            ['code' => 'RKT8YQ', 'profile' => '30-Hari-Online', 'expires' => '2026-05-30 17:25:02'],
            ['code' => 'C942T8', 'profile' => '7-Hari-Online',  'expires' => '2026-05-07 19:00:01'],
            ['code' => 'HQSDEJ', 'profile' => '30-Hari-Online', 'expires' => '2026-06-01 15:10:02'],
            ['code' => 'M5FWSZ', 'profile' => '30-Hari-Online', 'expires' => '2026-06-01 22:05:02'],
            ['code' => '2PSGUC', 'profile' => '7-Hari-Online',  'expires' => '2026-05-09 22:20:01'],
            ['code' => 'AT5Y3F', 'profile' => '30-Hari-Online', 'expires' => '2026-06-02 01:15:02'],
            ['code' => 'P3TGMB', 'profile' => '1-Hari-Online',  'expires' => '2026-05-04 13:00:02'],
            ['code' => 'TSMC39', 'profile' => '1-Hari-Online',  'expires' => '2026-05-04 13:05:01'],
        ];

        foreach ($data as $item) {
            $plan = VoucherPlan::where('mikrotik_profile', $item['profile'])->first();
            if (!$plan) {
                $this->error("Plan not found for profile: {$item['profile']}");
                continue;
            }

            Voucher::updateOrCreate(
                ['code' => $item['code']],
                [
                    'voucher_plan_id' => $plan->id,
                    'status' => 'used',
                    'used_at' => Carbon::now(), // Mark as already used
                    'expires_at' => Carbon::parse($item['expires']),
                    'price' => $plan->price,
                ]
            );

            $this->info("Imported: {$item['code']} (Expires: {$item['expires']})");
        }

        $this->info("Legacy vouchers import completed!");
    }
}
