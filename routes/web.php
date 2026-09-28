<?php

use Illuminate\Support\Facades\Route;

// /api・/sanctum・ビルド資源・PWA のファイル以外は、すべて SPA の HTML を返す。
// 未ログインでも返す（ログイン判定は SPA が GET /api/me で行う）。
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '(?!api(?:/|$)|sanctum(?:/|$)|build/|up$|manifest\.webmanifest$|sw\.js$|workbox-[^/]+\.js$|icons/).*')
    ->name('spa');
