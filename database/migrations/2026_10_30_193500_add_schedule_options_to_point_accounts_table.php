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
            $table->string('auto_debit_trigger_type', 30)->default('on_expiry')->after('auto_debit_plan_id');
            $table->unsignedTinyInteger('auto_debit_day')->nullable()->after('auto_debit_trigger_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'auto_debit_trigger_type',
                'auto_debit_day',
            ]);
        });
    }
};

