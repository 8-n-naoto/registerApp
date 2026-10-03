<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 15 §4：レシートプリンター（WebPRNT）の宛先と紙の幅。printer_host が NULL の店舗は印刷の機能を出さない。
// 既存の店舗はすべて NULL（未登録）・80mm のまま
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stores ADD COLUMN printer_host VARCHAR(100) NULL');
        DB::statement('ALTER TABLE stores ADD COLUMN printer_paper_width SMALLINT NOT NULL DEFAULT 80');
    }

    public function down(): void
    {
        Schema::table('stores', function ($table) {
            $table->dropColumn(['printer_host', 'printer_paper_width']);
        });
    }
};
