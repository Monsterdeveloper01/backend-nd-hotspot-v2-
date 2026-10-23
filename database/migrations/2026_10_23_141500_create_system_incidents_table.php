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
        Schema::create('system_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50)->index(); // whatsapp, payment_fraud, mikrotik, billing, voucher, auth, system
            $table->string('severity', 20)->default('warning')->index(); // critical, warning, info
            $table->string('title');
            $table->text('message');
            $table->longText('technical_details')->nullable();
            $table->json('payload')->nullable();
            $table->string('action_type', 50)->nullable()->index(); // retry_whatsapp, manual_isolate, check_payment, none
            $table->string('status', 30)->default('unresolved')->index(); // unresolved, retried, resolved
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_incidents');
    }
};