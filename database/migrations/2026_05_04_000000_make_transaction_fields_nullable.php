<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_plan_id')->nullable()->change();
            $table->unsignedBigInteger('voucher_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_plan_id')->nullable(false)->change();
            $table->unsignedBigInteger('voucher_id')->nullable(false)->change();
        });
    }
};
