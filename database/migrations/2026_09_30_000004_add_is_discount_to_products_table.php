<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 割引の商品（docs/10「割引の商品」）。価格は正の数のまま持ち、計算では −価格 の明細として扱う。
// price >= 0 の CHECK は変えない（SQLite で表を作り直さずに済むため）。既存の商品は通常の商品のまま
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products ADD COLUMN is_discount BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        Schema::table('products', function ($table) {
            $table->dropColumn('is_discount');
        });
    }
};
