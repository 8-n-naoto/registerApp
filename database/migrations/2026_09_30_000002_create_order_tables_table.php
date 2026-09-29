<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 12 §3.3（テーブルと QR のトークン）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->string('name', 20);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->dateTime('token_rotated_at');
            $table->dateTime('opened_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'sort_order']);
        });

        // 削除済みのテーブル名は再利用できるよう、一意性は削除されていないものだけで見る
        DB::statement('CREATE UNIQUE INDEX order_tables_store_id_name_unique ON order_tables(store_id, name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tables');
    }
};
