<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 05 §3.6。CHECK 制約（価格・在庫は 0 以上）があるため DDL で作る
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE products (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              store_id INTEGER NOT NULL REFERENCES stores(id),
              category_id INTEGER NULL REFERENCES categories(id),
              name VARCHAR(50) NOT NULL,
              price INTEGER NOT NULL CHECK (price >= 0),
              color VARCHAR(10) NOT NULL DEFAULT 'gray',
              sort_order INTEGER NOT NULL DEFAULT 0,
              is_active BOOLEAN NOT NULL DEFAULT 1,
              track_stock BOOLEAN NOT NULL DEFAULT 0,
              stock_qty INTEGER NOT NULL DEFAULT 0 CHECK (stock_qty >= 0),
              created_at DATETIME NULL,
              updated_at DATETIME NULL,
              deleted_at DATETIME NULL
            )
            SQL);
        DB::statement('CREATE INDEX products_store_id_category_id_sort_order_index ON products(store_id, category_id, sort_order)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
