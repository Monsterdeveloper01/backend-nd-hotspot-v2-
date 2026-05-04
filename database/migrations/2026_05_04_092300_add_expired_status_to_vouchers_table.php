<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL for ENUM update as Blueprint doesn't support changing ENUM easily across all DBs
        DB::statement("ALTER TABLE vouchers MODIFY COLUMN status ENUM('available', 'sold', 'used', 'expired') DEFAULT 'available'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE vouchers MODIFY COLUMN status ENUM('available', 'sold', 'used') DEFAULT 'available'");
    }
};
