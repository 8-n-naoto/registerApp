<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

// 06 §13 の 45 ルートは各 WP で追加する（並びと権限は docs/04 §4.9）。

// 1 ─ 認証（未ログイン）。06 §3.1 により guest は付けない（ログイン中でも照合し、成功すれば切り替える）
Route::post('/login', [Api\AuthController::class, 'login']);                                        // #1

Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function () {

    // 2〜4 ─ 自分のアカウント（全役割）
    Route::post('/logout', [Api\AuthController::class, 'logout']);                                  // #2
    Route::get('/me', [Api\MeController::class, 'show']);                                           // #3
    Route::put('/me/password', [Api\MeController::class, 'updatePassword']);                        // #4
});
