<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 12 §3.1・§3.2・§3.7（docs/sql/0002_orders.sql）。店舗の在庫管理・注文の設定、会計の在庫減算の写し、お客さんのメニューへの表示。
// 既存の店舗・会計は「在庫管理 ON・減算済み」（現状の動作のまま）。CHECK 制約があるため DDL で追加する
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stores ADD COLUMN stock_enabled BOOLEAN NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE stores ADD COLUMN customer_order_enabled BOOLEAN NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE stores ADD COLUMN customer_order_approval BOOLEAN NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE stores ADD COLUMN customer_session_minutes INTEGER NOT NULL DEFAULT 180
            CHECK (customer_session_minutes BETWEEN 30 AND 720)');
        DB::statement("ALTER TABLE stores ADD COLUMN polling_mode VARCHAR(10) NOT NULL DEFAULT 'always'
            CHECK (polling_mode IN ('always', 'off', 'schedule'))");
        DB::statement('ALTER TABLE stores ADD COLUMN polling_windows TEXT NULL');
        DB::statement('ALTER TABLE stores ADD COLUMN order_rev INTEGER NOT NULL DEFAULT 0');

        DB::statement('ALTER TABLE sales ADD COLUMN stock_applied BOOLEAN NOT NULL DEFAULT 1');

        DB::statement('ALTER TABLE products ADD COLUMN customer_visible BOOLEAN NOT NULL DEFAULT 1');
    }

    public function down(): void
    {
        Schema::table('products', function ($table) {
            $table->dropColumn('customer_visible');
        });
        Schema::table('sales', function ($table) {
            $table->dropColumn('stock_applied');
        });
        Schema::table('stores', function ($table) {
            $table->dropColumn([
                'stock_enabled', 'customer_order_enabled', 'customer_order_approval', 'customer_session_minutes',
                'polling_mode', 'polling_windows', 'order_rev',
            ]);
        });
    }
};
