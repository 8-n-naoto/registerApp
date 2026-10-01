<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 13 §3.5（勤務表）。時刻は 'HH:MM'（終了は翌朝の '29:59' まで）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->char('month', 7);
            $table->date('request_deadline')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('memo', 500)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'month']);
        });

        Schema::create('shift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->date('date');
            $table->string('kind', 12);
            $table->char('start_time', 5)->nullable();
            $table->char('end_time', 5)->nullable();
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['store_id', 'date']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->date('date');
            $table->char('start_time', 5);
            $table->char('end_time', 5);
            $table->integer('break_minutes')->default(0);
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_requests');
        Schema::dropIfExists('shift_months');
    }
};
