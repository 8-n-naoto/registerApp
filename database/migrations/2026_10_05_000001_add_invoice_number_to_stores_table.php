<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// インボイス制度の登録番号（T + 13 桁）。NULL の店舗はレシートに登録番号の行を出さない。
// 既存の店舗はすべて NULL（未登録）のまま
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stores ADD COLUMN invoice_number VARCHAR(14) NULL');
    }

    public function down(): void
    {
        Schema::table('stores', function ($table) {
            $table->dropColumn('invoice_number');
        });
    }
};
