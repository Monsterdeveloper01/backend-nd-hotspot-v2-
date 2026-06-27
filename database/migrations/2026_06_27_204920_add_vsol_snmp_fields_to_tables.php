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
        Schema::table('onu_nodes', function (Blueprint $table) {
            $table->string('description')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // make sure last_signal is float and nullable
            $table->float('last_signal')->nullable()->change();
        });

        Schema::table('olt_configs', function (Blueprint $table) {
            $table->timestamp('last_synced_at')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('onu_serial')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('onu_nodes', function (Blueprint $table) {
            $table->dropColumn(['description', 'last_seen_at']);
        });

        Schema::table('olt_configs', function (Blueprint $table) {
            $table->dropColumn('last_synced_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('onu_serial');
        });
    }
};
