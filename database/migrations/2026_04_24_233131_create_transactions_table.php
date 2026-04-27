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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique(); // Midtrans Order ID
            $table->foreignId('voucher_plan_id')->constrained();
            $table->foreignId('voucher_id')->nullable()->constrained();
            $table->string('customer_phone');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending'); // pending, success, failed, expired
            $table->string('payment_method')->default('qris');
            $table->text('payment_url')->nullable(); // QRIS URL/QR Image
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
