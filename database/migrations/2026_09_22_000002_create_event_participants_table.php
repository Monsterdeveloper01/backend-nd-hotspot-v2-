<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 1: Event System Analytics — event_participants table
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE event_participants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id BIGINT UNSIGNED NOT NULL,
                phone VARCHAR(20) NOT NULL,
                period_key VARCHAR(7) NOT NULL,
                total_purchase DECIMAL(15,2) DEFAULT 0,
                transaction_count INT DEFAULT 0,
                first_transaction_at TIMESTAMP NULL,
                last_transaction_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                UNIQUE KEY unique_participant (event_id, phone, period_key),
                INDEX idx_phone (phone),
                INDEX idx_period (period_key),
                INDEX idx_event_period (event_id, period_key)
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_participants');
    }
};
