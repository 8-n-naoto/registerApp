<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 05 §3.1。users が stores を参照するため、標準の users より前に作る
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->string('price_mode', 20)->default('tax_included');
            $table->string('rounding', 10)->default('floor');
            $table->char('day_cutoff_time', 5)->default('00:00');
            $table->dateTime('initialized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
