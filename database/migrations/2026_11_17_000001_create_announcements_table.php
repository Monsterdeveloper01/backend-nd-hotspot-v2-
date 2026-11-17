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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 30)->default('maintenance'); // maintenance, warning, info, promo
            $table->text('message');
            $table->dateTime('start_time')->nullable(); // Kapan mulai maintenance
            $table->dateTime('end_time')->nullable();   // Kapan selesai maintenance
            $table->dateTime('expires_at')->nullable(); // Kapan otomatis kedaluwarsa & dihapus
            $table->boolean('is_active')->default(true);
            $table->boolean('dismissible')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
