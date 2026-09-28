<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 05 §3.11
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->date('business_date');
            $table->integer('float_amount')->default(0);
            $table->integer('cash_sales');
            $table->integer('expected_cash');
            $table->integer('counted_cash');
            $table->integer('difference');
            $table->string('memo', 200)->nullable();
            $table->boolean('changed_after_close')->default(false);
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->unique(['store_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_closings');
    }
};
