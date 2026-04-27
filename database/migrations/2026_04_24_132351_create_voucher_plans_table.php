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
        Schema::create('voucher_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('duration')->nullable(); // e.g. "7h", "1d"
            $table->string('upload_limit')->nullable(); // e.g. "512k"
            $table->string('download_limit')->nullable(); // e.g. "1M"
            $table->integer('shared_users')->default(1);
            $table->integer('price')->default(0);
            $table->string('mikrotik_profile')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_plans');
    }
};
