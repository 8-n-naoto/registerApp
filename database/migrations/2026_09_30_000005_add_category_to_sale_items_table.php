<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 05 §3.9。会計の明細にカテゴリの写しを追加する（カテゴリ別の売上。docs/10「カテゴリ別の売上」）。
// 外部キーは付けない（カテゴリを削除・変更しても過去の会計の分け方を変えない）。
// 既存の明細には、この時点の商品（削除済みも含む）のカテゴリを写す
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sale_items ADD COLUMN category_id INTEGER NULL');
        DB::statement('ALTER TABLE sale_items ADD COLUMN category_name VARCHAR(30) NULL');

        DB::statement('UPDATE sale_items SET
            category_id = (SELECT categories.id FROM products JOIN categories ON categories.id = products.category_id
                WHERE products.id = sale_items.product_id),
            category_name = (SELECT categories.name FROM products JOIN categories ON categories.id = products.category_id
                WHERE products.id = sale_items.product_id)');
    }

    public function down(): void
    {
        Schema::table('sale_items', function ($table) {
            $table->dropColumn(['category_id', 'category_name']);
        });
    }
};
