<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 05 §3.6。商品コード（店舗内で一意。空欄なら P0001 形式で自動採番）とメモを追加する。
// 既存の商品には店舗ごとに id 順で P0001 から振る（削除済みも含めて番号を重ねない）
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE products ADD COLUMN code VARCHAR(20) NOT NULL DEFAULT ''");
        DB::statement('ALTER TABLE products ADD COLUMN memo VARCHAR(200) NULL');

        $storeIds = DB::table('products')->distinct()->orderBy('store_id')->pluck('store_id');
        foreach ($storeIds as $storeId) {
            $ids = DB::table('products')->where('store_id', $storeId)->orderBy('id')->pluck('id');
            foreach ($ids as $i => $id) {
                DB::table('products')->where('id', $id)->update(['code' => sprintf('P%04d', $i + 1)]);
            }
        }

        // 削除済みの商品のコードは再利用できるよう、一意性は削除されていない商品だけで見る
        DB::statement('CREATE UNIQUE INDEX products_store_id_code_unique ON products(store_id, code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_store_id_code_unique');
        Schema::table('products', function ($table) {
            $table->dropColumn(['code', 'memo']);
        });
    }
};
