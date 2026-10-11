<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 17 §3.1：品目単位の取消。cancelled_at が入った品目は小計・提供の完了・在庫の引当・会計への取り込みから除く。
// 既存の品目はすべて NULL（取り消されていない）のまま
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE order_items ADD COLUMN cancelled_at DATETIME NULL');
        DB::statement('ALTER TABLE order_items ADD COLUMN cancelled_by INTEGER NULL REFERENCES users(id)');
    }

    public function down(): void
    {
        Schema::table('order_items', function ($table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });
    }
};
