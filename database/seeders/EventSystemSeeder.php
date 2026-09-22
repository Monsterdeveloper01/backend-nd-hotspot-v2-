<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\VoucherPlan;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EventSystemSeeder
 * 
 * Seeds dummy voucher transactions for local testing of Event System Analytics.
 * Creates transactions with various phone formats to test:
 *   - Phone normalization (08xxx, 628xxx, +628xxx, 8xxx)
 *   - Monthly grouping (September, October)
 *   - Total purchase aggregation
 *   - Transaction count
 *   - Sync idempotency
 * 
 * Expected results after sync:
 *   Customer A (08123456789 → 628123456789) September = Rp60.000 (3 tx)
 *   Customer B (628987654321 → 628987654321) September = Rp100.000 (2 tx)
 *   Customer B (628987654321 → 628987654321) October   = Rp25.000 (1 tx)
 *   Customer C (+628555666777 → 628555666777) September = Rp150.000 (1 tx)
 *   Customer D (8119198687 → 628119198687) September  = Rp35.000 (2 tx)
 *   Invalid phone (null) = skipped
 *   Invalid phone ("abc") = skipped
 * 
 * DO NOT run this in production.
 */
class EventSystemSeeder extends Seeder
{
    public function run(): void
    {
        // Get or create a voucher plan for seeding
        $plan = VoucherPlan::first();
        if (!$plan) {
            $plan = VoucherPlan::create([
                'name' => 'Test Plan 2K',
                'duration' => '7h',
                'price' => 2000,
                'shared_users' => 1,
            ]);
        }

        $transactions = [
            // Customer A: 08123456789 (format 08xxx) — September, 3 transactions
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '08123456789',
                'amount' => 10000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 5, 10, 30),
                'updated_at' => Carbon::create(2026, 9, 5, 10, 30),
            ],
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '08123456789',
                'amount' => 20000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 12, 14, 0),
                'updated_at' => Carbon::create(2026, 9, 12, 14, 0),
            ],
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '08123456789',
                'amount' => 30000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 20, 9, 15),
                'updated_at' => Carbon::create(2026, 9, 20, 9, 15),
            ],

            // Customer B: 628987654321 (format 628xxx) — September, 2 transactions
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '628987654321',
                'amount' => 50000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 8, 11, 0),
                'updated_at' => Carbon::create(2026, 9, 8, 11, 0),
            ],
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '628987654321',
                'amount' => 50000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 15, 16, 30),
                'updated_at' => Carbon::create(2026, 9, 15, 16, 30),
            ],

            // Customer B: same phone — October, 1 transaction
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '628987654321',
                'amount' => 25000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 10, 3, 8, 45),
                'updated_at' => Carbon::create(2026, 10, 3, 8, 45),
            ],

            // Customer C: +628555666777 (format +628xxx) — September, 1 transaction
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '+628555666777',
                'amount' => 150000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 25, 13, 0),
                'updated_at' => Carbon::create(2026, 9, 25, 13, 0),
            ],

            // Customer D: 8119198687 (format 8xxx, no leading 0 or 62) — September, 2 transactions
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '8119198687',
                'amount' => 15000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 10, 10, 0),
                'updated_at' => Carbon::create(2026, 9, 10, 10, 0),
            ],
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '8119198687',
                'amount' => 20000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 18, 15, 0),
                'updated_at' => Carbon::create(2026, 9, 18, 15, 0),
            ],

            // Invalid phone: null — should be skipped during sync
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => null,
                'amount' => 5000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 22, 12, 0),
                'updated_at' => Carbon::create(2026, 9, 22, 12, 0),
            ],

            // Invalid phone: "abc" — should be skipped during sync
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => 'abc',
                'amount' => 5000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 22, 13, 0),
                'updated_at' => Carbon::create(2026, 9, 22, 13, 0),
            ],

            // Non-ND transaction (should NOT be picked up by sync)
            [
                'external_id' => 'BILL-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '08123456789',
                'amount' => 100000,
                'status' => 'success',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 15, 10, 0),
                'updated_at' => Carbon::create(2026, 9, 15, 10, 0),
            ],

            // Pending transaction (should NOT be picked up by sync)
            [
                'external_id' => 'ND-SEED-' . Str::random(8),
                'voucher_plan_id' => $plan->id,
                'customer_phone' => '08123456789',
                'amount' => 50000,
                'status' => 'pending',
                'payment_method' => 'qris',
                'created_at' => Carbon::create(2026, 9, 28, 10, 0),
                'updated_at' => Carbon::create(2026, 9, 28, 10, 0),
            ],
        ];

        foreach ($transactions as $tx) {
            DB::table('transactions')->insert($tx);
        }

        $this->command->info('EventSystemSeeder: Seeded ' . count($transactions) . ' test transactions.');
        $this->command->info('Expected after sync (Sept-Oct 2026):');
        $this->command->info('  Customer A (628123456789) Sept = Rp60.000 (3 tx)');
        $this->command->info('  Customer B (628987654321) Sept = Rp100.000 (2 tx)');
        $this->command->info('  Customer B (628987654321) Oct  = Rp25.000 (1 tx)');
        $this->command->info('  Customer C (628555666777) Sept = Rp150.000 (1 tx)');
        $this->command->info('  Customer D (628119198687) Sept = Rp35.000 (2 tx)');
        $this->command->info('  Skipped: 2 (null + "abc")');
        $this->command->info('  Ignored: 1 BILL + 1 pending');
    }
}
