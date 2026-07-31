<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix: customer_phone harus nullable agar QRIS Statis (tanpa customer) bisa tersimpan.
     * Migration sebelumnya (2026_04_29) kosong dan tidak menjalankan perubahan ini.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('customer_phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('customer_phone')->nullable(false)->change();
        });
    }
};
