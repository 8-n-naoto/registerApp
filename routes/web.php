<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// 死活監視（SEC-006）。APP_HEALTH_PATH を設定したときだけ有効。データベースに接続できなければ 500 になる。
// 応答は "OK" の文字だけにし、フレームワーク名を出さない
$healthPath = config('app.health_path');
if (is_string($healthPath) && preg_match('/\A[A-Za-z0-9_-]{16,}\z/', $healthPath) === 1) {
    Route::get('/'.$healthPath, function () {
        DB::select('select 1');

        return response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    })->name('health');
}

// /api・/sanctum・ビルド資源・PWA のファイル以外は、すべて SPA の HTML を返す。
// 未ログインでも返す（ログイン判定は SPA が GET /api/me で行う）。
// 拡張子の付いたパス（/robots.txt・/.env・/xxx.php など）・/.well-known/・/up は SPA の画面ではないため 404 にする（QA-003・SEC-006。
// SPA の画面のパスには「.」を使わない。テーブルの QR のトークンも英数字と - _ だけ）
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '(?!api(?:/|$)|sanctum(?:/|$)|build/|up$|\.well-known(?:/|$)|[^?]*\.[A-Za-z0-9]+$|icons/).*')
    ->name('spa');
