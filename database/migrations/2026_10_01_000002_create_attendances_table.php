<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 13 §3.3・§3.4（打刻と休憩）。時給は出勤時点の写し
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->date('business_date');
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            $table->integer('hourly_wage')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['store_id', 'business_date']);
            $table->index(['user_id', 'clock_in_at']);
        });

        Schema::create('attendance_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['attendance_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_breaks');
        Schema::dropIfExists('attendances');
    }
};
