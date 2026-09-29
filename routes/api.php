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

    // owner のみ。/order は /{id} より先に登録し、ID は数値に限る（04 §4.9）
    Route::middleware('role:owner')->group(function () {
        // 商品
        Route::get('/products', [Api\ProductController::class, 'index']);                           // #14
        Route::post('/products', [Api\ProductController::class, 'store']);                          // #15
        Route::put('/products/order', [Api\ProductController::class, 'reorder']);                   // #19
        Route::put('/products/{product}', [Api\ProductController::class, 'update'])->whereNumber('product');      // #16
        Route::delete('/products/{product}', [Api\ProductController::class, 'destroy'])->whereNumber('product');  // #17
        Route::patch('/products/{product}/stock', [Api\ProductStockController::class, 'update'])->whereNumber('product'); // #18

        // カテゴリ
        Route::get('/categories', [Api\CategoryController::class, 'index']);                        // #21
        Route::post('/categories', [Api\CategoryController::class, 'store']);                       // #22
        Route::put('/categories/order', [Api\CategoryController::class, 'reorder']);                // #25
        Route::put('/categories/{category}', [Api\CategoryController::class, 'update'])->whereNumber('category');     // #23
        Route::delete('/categories/{category}', [Api\CategoryController::class, 'destroy'])->whereNumber('category'); // #24

        // オプション
        Route::post('/products/{product}/options', [Api\ProductOptionController::class, 'store'])->whereNumber('product');           // #26
        Route::put('/products/{product}/options/order', [Api\ProductOptionController::class, 'reorder'])->whereNumber('product');    // #29
        Route::put('/options/{option}', [Api\ProductOptionController::class, 'update'])->whereNumber('option');                      // #27
        Route::delete('/options/{option}', [Api\ProductOptionController::class, 'destroy'])->whereNumber('option');                  // #28
    });
});
