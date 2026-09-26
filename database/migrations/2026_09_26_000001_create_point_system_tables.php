<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * ND-POINT: Silent Tracking Phase — Core Engine, Rules, Account, and Ledger
     */
    public function up(): void
    {
        // 1. Configurable Point Rules table
        Schema::create('point_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 50)->default('purchase'); // purchase, event, bonus, multiplier
            $table->string('calculation_type', 50)->default('per_unit'); // per_unit, fixed_bonus, percentage, multiplier
            $table->decimal('value', 15, 2)->default(10.00); // e.g. 10 points per unit
            $table->decimal('unit_amount', 15, 2)->nullable()->default(1000.00); // e.g. every Rp 1.000
            $table->decimal('min_purchase_amount', 15, 2)->nullable()->default(1000.00);
            $table->decimal('max_purchase_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('event_id')->nullable();
            $table->integer('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'type'], 'idx_point_rules_active_type');
            $table->index('priority', 'idx_point_rules_priority');
        });

        // 2. Point Accounts (One account per normalized phone)
        Schema::create('point_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('lifetime_earned')->default(0);
            $table->bigInteger('lifetime_spent')->default(0);
            $table->string('status', 20)->default('active'); // active, frozen, suspended
            $table->timestamps();

            $table->index('balance', 'idx_point_accounts_balance');
            $table->index('lifetime_earned', 'idx_point_accounts_earned');
            $table->index('status', 'idx_point_accounts_status');
        });

        // 3. Point Transactions (Ledger: every movement recorded with before & after balance)
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('point_account_id')->constrained('point_accounts')->onDelete('cascade');
            $table->string('phone', 20);
            $table->enum('type', ['earn', 'spend', 'adjustment', 'expire', 'refund'])->default('earn');
            $table->string('source_type', 50)->default('purchase'); // purchase, reward, admin, system
            $table->string('source_id', 100)->nullable(); // e.g. transaction_id
            $table->bigInteger('points'); // points amount credited (+) or debited (-)
            $table->bigInteger('balance_before')->default(0);
            $table->bigInteger('balance_after')->default(0);
            $table->string('description', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Strict Idempotency: the same source cannot award/process the exact same type twice
            $table->unique(['source_type', 'source_id', 'type'], 'point_tx_source_unique');
            $table->index(['phone', 'created_at'], 'idx_point_tx_phone_created');
            $table->index('type', 'idx_point_tx_type');
        });

        // 4. Seed initial default configurable rule: Rp 1.000 = 10 ND-Point
        DB::table('point_rules')->insert([
            'name' => 'Default Purchase Reward (Rp 1.000 = 10 Pts)',
            'description' => 'Mendapatkan 10 ND-Point untuk setiap kelipatan Rp 1.000 pembelian voucher yang berhasil',
            'type' => 'purchase',
            'calculation_type' => 'per_unit',
            'value' => 10.00,
            'unit_amount' => 1000.00,
            'min_purchase_amount' => 1000.00,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('point_accounts');
        Schema::dropIfExists('point_rules');
    }
};
