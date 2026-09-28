<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 05 §3.3。CHECK 制約があるため DDL で作る
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE tax_types (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              store_id INTEGER NOT NULL REFERENCES stores(id),
              name VARCHAR(20) NOT NULL,
              rate_permille INTEGER NOT NULL CHECK (rate_permille BETWEEN 0 AND 1000),
              sort_order INTEGER NOT NULL DEFAULT 0,
              is_default BOOLEAN NOT NULL DEFAULT 0,
              is_active BOOLEAN NOT NULL DEFAULT 1,
              created_at DATETIME NULL,
              updated_at DATETIME NULL
            )
            SQL);
        DB::statement('CREATE INDEX tax_types_store_id_sort_order_index ON tax_types(store_id, sort_order)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_types');
    }
};
