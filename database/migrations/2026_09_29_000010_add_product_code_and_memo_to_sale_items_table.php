<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 05 §3.9。会計の明細に商品コードとメモの写しを追加する（同じ名前の商品を売上で見分けるため）。
// 既存の明細には、この時点の商品（削除済みも含む）のコード・メモを写す
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sale_items ADD COLUMN product_code VARCHAR(20) NOT NULL DEFAULT ''");
        DB::statement('ALTER TABLE sale_items ADD COLUMN product_memo VARCHAR(200) NULL');

        DB::statement('UPDATE sale_items SET
            product_code = (SELECT products.code FROM products WHERE products.id = sale_items.product_id),
            product_memo = (SELECT products.memo FROM products WHERE products.id = sale_items.product_id)
            WHERE EXISTS (SELECT 1 FROM products WHERE products.id = sale_items.product_id)');
    }

    public function down(): void
    {
        Schema::table('sale_items', function ($table) {
            $table->dropColumn(['product_code', 'product_memo']);
        });
    }
};
