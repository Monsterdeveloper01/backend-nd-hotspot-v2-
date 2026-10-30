<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('point_accounts', function (Blueprint $table) {
            $table->boolean('auto_debit_enabled')->default(false)->after('locked_until');
            $table->unsignedBigInteger('auto_debit_plan_id')->nullable()->after('auto_debit_enabled');
            $table->timestamp('auto_debit_last_run_at')->nullable()->after('auto_debit_plan_id');
            $table->unsignedTinyInteger('auto_debit_fail_count')->default(0)->after('auto_debit_last_run_at');

            $table->index('auto_debit_enabled', 'idx_point_acc_autodebit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_point_acc_autodebit');
            $table->dropColumn([
                'auto_debit_enabled',
                'auto_debit_plan_id',
                'auto_debit_last_run_at',
                'auto_debit_fail_count',
            ]);
        });
    }
};
