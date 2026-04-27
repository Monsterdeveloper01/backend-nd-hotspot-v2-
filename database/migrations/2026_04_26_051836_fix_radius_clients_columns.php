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
        Schema::table('radius_clients', function (Blueprint $table) {
            $table->text('ip_address')->change();
            $table->text('shared_secret')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('radius_clients', function (Blueprint $table) {
            $table->string('ip_address')->unique()->change();
            $table->string('shared_secret')->change();
        });
    }
};
