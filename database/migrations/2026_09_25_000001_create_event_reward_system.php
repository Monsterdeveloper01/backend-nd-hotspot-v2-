<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 2: Automatic Reward System
     */
    public function up(): void
    {
        // 1. Reward Rules table: defines reward configurations linked to voucher_plans
        // NOTE: target_amount is strictly inherited from events.target_amount (no duplicate target_amount column)
        Schema::create('event_reward_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('voucher_plan_id')->constrained('voucher_plans')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('event_id', 'idx_rule_event');
            $table->index('is_active', 'idx_rule_active');
        });

        // 2. Event Rewards table: tracking customer rewards and lifecycle
        Schema::create('event_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('event_reward_rule_id')->constrained('event_reward_rules')->onDelete('cascade');
            $table->string('phone', 20);
            $table->string('period_key', 7); // YYYY-MM
            $table->string('reward_type', 50)->default('voucher');
            $table->string('reward_value', 255)->nullable();
            $table->enum('status', [
                'processing',
                'issued',
                'used',
                'expired',
                'cancelled',
                'failed'
            ])->default('processing');
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Strict idempotency: exactly ONE reward per (event_id, phone, period_key, event_reward_rule_id)
            $table->unique(
                ['event_id', 'phone', 'period_key', 'event_reward_rule_id'],
                'unique_event_phone_period_rule'
            );
            $table->index('status', 'idx_reward_status');
            $table->index(['phone', 'period_key'], 'idx_reward_phone_period');
        });

        // 3. Add source column to vouchers table to separate purchases from rewards
        if (Schema::hasTable('vouchers') && !Schema::hasColumn('vouchers', 'source')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->enum('source', ['purchase', 'reward'])->default('purchase')->after('customer_phone');
                $table->index('source', 'idx_vouchers_source');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('vouchers') && Schema::hasColumn('vouchers', 'source')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropIndex('idx_vouchers_source');
                $table->dropColumn('source');
            });
        }

        Schema::dropIfExists('event_rewards');
        Schema::dropIfExists('event_reward_rules');
    }
};
