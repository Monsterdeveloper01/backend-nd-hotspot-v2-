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
        Schema::table('radius_logs', function (Blueprint $table) {
            $table->index('username');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('radius_logs', function (Blueprint $table) {
            $table->dropIndex(['username']);
            $table->dropIndex(['created_at']);
        });
    }
};
