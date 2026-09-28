<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 05 §1・§3.2：初回リリース前に限り標準の users を書き換える（email → login_id、password_reset_tokens は作らない）
return new class extends Migration
{
    public function up(): void
    {
        // Schema ビルダーは CHECK 制約を作れないため、05 の DDL（docs/sql/0001_init.sql）をそのまま流す
        DB::statement(<<<'SQL'
            CREATE TABLE users (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              store_id INTEGER NULL REFERENCES stores(id),
              role VARCHAR(10) NOT NULL,
              login_id VARCHAR(50) NOT NULL,
              name VARCHAR(50) NOT NULL,
              password VARCHAR(255) NOT NULL,
              is_active BOOLEAN NOT NULL DEFAULT 1,
              remember_token VARCHAR(100) NULL,
              last_login_at DATETIME NULL,
              created_at DATETIME NULL,
              updated_at DATETIME NULL,
              CHECK ((role = 'admin' AND store_id IS NULL) OR (role IN ('owner', 'staff') AND store_id IS NOT NULL))
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX users_login_id_unique ON users(login_id)');
        DB::statement('CREATE INDEX users_store_id_index ON users(store_id)');

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
