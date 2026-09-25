<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 2: Loyalty Test Mode Isolation Schema
     */
    public function up(): void
    {
        // 1. Table for storing simulated test states without touching transactions table
        Schema::create('event_test_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('phone', 20);
            $table->string('period_key', 7); // YYYY-MM
            $table->decimal('simulated_total_purchase', 15, 2)->default(0);
            $table->integer('simulated_transaction_count')->default(1);
            $table->boolean('use_real_mikrotik')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'phone', 'period_key'], 'unique_event_phone_period_test');
            $table->index(['phone', 'period_key'], 'idx_test_phone_period');
        });

        // 2. Add is_test flag to event_rewards to separate simulation rewards from production rewards
        if (Schema::hasTable('event_rewards') && !Schema::hasColumn('event_rewards', 'is_test')) {
            Schema::table('event_rewards', function (Blueprint $table) {
                $table->boolean('is_test')->default(false)->after('status');
                $table->index('is_test', 'idx_reward_is_test');
            });
        }

        // 3. Add is_test flag to vouchers to safely identify test vouchers for reset
        if (Schema::hasTable('vouchers') && !Schema::hasColumn('vouchers', 'is_test')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->boolean('is_test')->default(false)->after('source');
                $table->index('is_test', 'idx_voucher_is_test');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('vouchers') && Schema::hasColumn('vouchers', 'is_test')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropIndex('idx_voucher_is_test');
                $table->dropColumn('is_test');
            });
        }

        if (Schema::hasTable('event_rewards') && Schema::hasColumn('event_rewards', 'is_test')) {
            Schema::table('event_rewards', function (Blueprint $table) {
                $table->dropIndex('idx_reward_is_test');
                $table->dropColumn('is_test');
            });
        }

        Schema::dropIfExists('event_test_states');
    }
};
