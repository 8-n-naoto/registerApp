<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 12 §3.4〜§3.6（注文・品目・品目のオプション）。orders・order_items は CHECK 制約があるため DDL で作る
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE orders (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              store_id INTEGER NOT NULL REFERENCES stores(id),
              client_uuid CHAR(36) NOT NULL,
              business_date DATE NOT NULL,
              order_no INTEGER NOT NULL,
              source VARCHAR(10) NOT NULL CHECK (source IN ('customer', 'staff')),
              order_table_id INTEGER NULL REFERENCES order_tables(id),
              table_name VARCHAR(20) NULL,
              label VARCHAR(20) NULL,
              status VARCHAR(10) NOT NULL CHECK (status IN ('pending', 'active', 'cancelled')),
              note VARCHAR(200) NULL,
              subtotal INTEGER NOT NULL CHECK (subtotal >= 0),
              served_at DATETIME NULL,
              sale_id INTEGER NULL REFERENCES sales(id),
              user_id INTEGER NULL REFERENCES users(id),
              accepted_at DATETIME NULL,
              accepted_by INTEGER NULL REFERENCES users(id),
              cancelled_at DATETIME NULL,
              cancelled_by INTEGER NULL REFERENCES users(id),
              device_name VARCHAR(30) NULL,
              created_at DATETIME NULL,
              updated_at DATETIME NULL
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX orders_store_id_client_uuid_unique ON orders(store_id, client_uuid)');
        DB::statement('CREATE UNIQUE INDEX orders_store_id_business_date_order_no_unique ON orders(store_id, business_date, order_no)');
        DB::statement('CREATE INDEX orders_store_id_status_served_at_index ON orders(store_id, status, served_at)');
        DB::statement('CREATE INDEX orders_store_id_sale_id_index ON orders(store_id, sale_id)');
        DB::statement('CREATE INDEX orders_order_table_id_created_at_index ON orders(order_table_id, created_at)');

        DB::statement(<<<'SQL'
            CREATE TABLE order_items (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              order_id INTEGER NOT NULL REFERENCES orders(id),
              product_id INTEGER NOT NULL REFERENCES products(id),
              product_code VARCHAR(20) NOT NULL,
              product_name VARCHAR(50) NOT NULL,
              product_memo VARCHAR(200) NULL,
              unit_price INTEGER NOT NULL,
              options_price INTEGER NOT NULL DEFAULT 0,
              quantity INTEGER NOT NULL CHECK (quantity BETWEEN 1 AND 99),
              line_total INTEGER NOT NULL,
              memo VARCHAR(50) NULL,
              served_at DATETIME NULL,
              served_by INTEGER NULL REFERENCES users(id),
              sort_order INTEGER NOT NULL DEFAULT 0,
              created_at DATETIME NULL,
              updated_at DATETIME NULL
            )
            SQL);
        DB::statement('CREATE INDEX order_items_order_id_index ON order_items(order_id)');
        DB::statement('CREATE INDEX order_items_product_id_index ON order_items(product_id)');

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->index()->constrained();
            $table->foreignId('product_option_id')->constrained();
            $table->string('option_name', 30);
            $table->integer('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
