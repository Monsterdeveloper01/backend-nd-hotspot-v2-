<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Transition events table to permanent event model (no start_date/end_date, status active/inactive).
     */
    public function up(): void
    {
        if (Schema::hasTable('events')) {
            // Drop start_date and end_date if they exist
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'start_date')) {
                    $table->dropColumn('start_date');
                }
                if (Schema::hasColumn('events', 'end_date')) {
                    $table->dropColumn('end_date');
                }
            });

            // Modify status column to ENUM('active','inactive') DEFAULT 'active'
            // First update any existing rows with legacy statuses
            DB::statement("UPDATE events SET status = 'active' WHERE status NOT IN ('active', 'inactive') OR status IS NULL");
            DB::statement("ALTER TABLE events MODIFY COLUMN status ENUM('active', 'inactive') DEFAULT 'active'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (!Schema::hasColumn('events', 'start_date')) {
                    $table->date('start_date')->nullable()->after('description');
                }
                if (!Schema::hasColumn('events', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
            });

            DB::statement("ALTER TABLE events MODIFY COLUMN status ENUM('draft', 'active', 'ended') DEFAULT 'draft'");
        }
    }
};
