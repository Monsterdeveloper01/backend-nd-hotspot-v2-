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
        Schema::create('olt_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->integer('port')->default(23); // Default Telnet
            $table->string('username');
            $table->string('password');
            $table->string('snmp_community')->default('public');
            $table->string('type')->default('generic'); // global, ad, vsol, etc
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('onu_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained('olt_configs')->onDelete('cascade');
            $table->string('onu_index'); // Identification on OLT (e.g. 1/1/1:1)
            $table->string('serial_number')->nullable()->unique();
            $table->string('alias')->nullable();
            $table->string('ip_address')->nullable();
            $table->float('last_signal')->nullable(); // Rx Power
            $table->float('last_temp')->nullable();
            $table->integer('client_count')->default(0);
            $table->timestamp('last_check')->nullable();
            $table->string('status')->default('offline');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_nodes');
        Schema::dropIfExists('olt_configs');
    }
};
