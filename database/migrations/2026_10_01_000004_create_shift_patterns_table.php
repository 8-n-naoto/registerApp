<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 13 §3.6（勤務の区分）。店舗ごとに「A 区分：09:00〜12:00 と 13:00〜15:00」のような時間帯の組を持つ。
// segments は [{"start":"09:00","end":"12:00"}, ...]（1〜3 個。間は休憩）。消さずに is_active で隠す。
// 予定と希望には選んだ区分の名前と時間帯を写す（あとで区分を直しても過去の予定・希望は変わらない）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->string('name', 20);
            $table->text('segments');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'name']);
        });

        foreach (['shifts', 'shift_requests'] as $table) {
            DB::statement("ALTER TABLE $table ADD COLUMN shift_pattern_id INTEGER NULL REFERENCES shift_patterns (id)");
            DB::statement("ALTER TABLE $table ADD COLUMN pattern_name VARCHAR(20) NULL");
            DB::statement("ALTER TABLE $table ADD COLUMN segments TEXT NULL");
        }
    }

    public function down(): void
    {
        foreach (['shifts', 'shift_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['shift_pattern_id', 'pattern_name', 'segments']);
            });
        }
        Schema::dropIfExists('shift_patterns');
    }
};
