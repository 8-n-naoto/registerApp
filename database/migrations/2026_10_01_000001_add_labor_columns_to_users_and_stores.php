<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 13 §3.1・§3.2（勤怠）。人ごとの時給と管理監督者・事業主の区分、店舗の労働条件（NULL = 未入力。owner に警告する）。
// 既存の owner は管理監督者・事業主として扱う（時間外・休日の割増を付けない。深夜は付ける）
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN hourly_wage INTEGER NULL CHECK (hourly_wage IS NULL OR hourly_wage BETWEEN 0 AND 100000)');
        DB::statement('ALTER TABLE users ADD COLUMN overtime_exempt BOOLEAN NOT NULL DEFAULT 0');
        DB::table('users')->where('role', 'owner')->update(['overtime_exempt' => 1]);

        DB::statement('ALTER TABLE stores ADD COLUMN weekly_hours_limit INTEGER NULL CHECK (weekly_hours_limit IS NULL OR weekly_hours_limit IN (40, 44))');
        DB::statement('ALTER TABLE stores ADD COLUMN week_start_day INTEGER NULL CHECK (week_start_day IS NULL OR week_start_day BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE stores ADD COLUMN legal_holiday_day INTEGER NULL CHECK (legal_holiday_day IS NULL OR legal_holiday_day BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE stores ADD COLUMN minimum_wage INTEGER NULL CHECK (minimum_wage IS NULL OR minimum_wage BETWEEN 1 AND 10000)');
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn(['hourly_wage', 'overtime_exempt']);
        });
        Schema::table('stores', function ($table) {
            $table->dropColumn(['weekly_hours_limit', 'week_start_day', 'legal_holiday_day', 'minimum_wage']);
        });
    }
};
