<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// オプションのグループ（docs/10「オプションのグループ」）。
// 既存のオプションは group_id = NULL（見出しのない「いくつでも」）のまま今までどおり動く。
// 注文の明細のオプションには、キッチンの表示用に「最初に選ぶ」と「1つ選ぶ」の写しを持たせる
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('name', 30);
            $table->string('selection', 10)->default('multi');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'sort_order']);
        });

        DB::statement('ALTER TABLE product_options ADD COLUMN group_id INTEGER NULL REFERENCES product_option_groups(id)');
        DB::statement('ALTER TABLE product_options ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE order_item_options ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE order_item_options ADD COLUMN is_choice TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        Schema::table('order_item_options', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'is_choice']);
        });
        Schema::table('product_options', function (Blueprint $table) {
            $table->dropColumn(['group_id', 'is_default']);
        });
        Schema::dropIfExists('product_option_groups');
    }
};
