<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 14 §3：オフライン会計（通信できない間に端末へ記録し、後から送った会計）の印と、送信時の問題の記録。
// 既存の会計はすべて is_offline = 0（通常の会計）のまま
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales ADD COLUMN is_offline BOOLEAN NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE sales ADD COLUMN client_sold_at DATETIME NULL');
        DB::statement('ALTER TABLE sales ADD COLUMN synced_at DATETIME NULL');
        DB::statement('ALTER TABLE sales ADD COLUMN synced_by INTEGER NULL REFERENCES users(id)');
        DB::statement('ALTER TABLE sales ADD COLUMN sync_issues TEXT NULL');
        DB::statement('ALTER TABLE sales ADD COLUMN issues_reviewed_at DATETIME NULL');
        DB::statement('ALTER TABLE sales ADD COLUMN issues_reviewed_by INTEGER NULL REFERENCES users(id)');
        DB::statement('CREATE INDEX sales_store_id_is_offline_index ON sales(store_id, is_offline)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX sales_store_id_is_offline_index');
        Schema::table('sales', function ($table) {
            $table->dropColumn([
                'is_offline', 'client_sold_at', 'synced_at', 'synced_by', 'sync_issues', 'issues_reviewed_at', 'issues_reviewed_by',
            ]);
        });
    }
};
