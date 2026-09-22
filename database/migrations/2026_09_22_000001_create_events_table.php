<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 1: Event System Analytics — events table
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                target_amount DECIMAL(15,2) DEFAULT 0,
                status ENUM('active','inactive') DEFAULT 'active',
                last_synced_at TIMESTAMP NULL,
                sync_stats JSON NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
