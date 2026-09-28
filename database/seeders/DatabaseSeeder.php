<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 開発用シーダー（05 §6.3）。local / testing のときだけ動き、本番では何もしない。
 * 中身は WP 1-6 で作る
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
    }
}
