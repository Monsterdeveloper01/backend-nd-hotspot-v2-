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
            $table->string('pin_hash', 255)->nullable()->after('status');
            $table->unsignedTinyInteger('failed_pin_attempts')->default(0)->after('pin_hash');
            $table->timestamp('locked_until')->nullable()->after('failed_pin_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_accounts', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'failed_pin_attempts', 'locked_until']);
        });
    }
};
