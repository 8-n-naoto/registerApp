<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

// 06 §13 の 45 ルートと 12 §5.0 の #46〜#65 は各 WP で追加する（並びと権限は docs/04 §4.9）。

// 1 ─ 認証（未ログイン）。06 §3.1 により guest は付けない（ログイン中でも照合し、成功すれば切り替える）
Route::post('/login', [Api\AuthController::class, 'login']);                                        // #1

Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function () {

    // 2〜4 ─ 自分のアカウント（全役割）
    Route::post('/logout', [Api\AuthController::class, 'logout']);                                  // #2
    Route::get('/me', [Api\MeController::class, 'show']);                                           // #3
    Route::put('/me/password', [Api\MeController::class, 'updatePassword']);                        // #4

    // owner / staff（admin 不可）
    Route::middleware('role:owner,staff')->group(function () {
        Route::get('/register/bootstrap', [Api\RegisterController::class, 'bootstrap']);            // #5
        Route::post('/sales', [Api\SaleController::class, 'store']);                                // #6
        Route::post('/sales/{sale}/cancel', [Api\SaleController::class, 'cancel'])->whereNumber('sale'); // #8
        Route::put('/closings/{date}', [Api\ClosingController::class, 'update']);                   // #13

        // テーブルの一覧・利用開始・終了（12 §5.11）
        Route::get('/order-tables', [Api\OrderTableController::class, 'index']);                   // #56
        Route::post('/order-tables/{orderTable}/open', [Api\OrderTableController::class, 'open'])->whereNumber('orderTable');   // #62
        Route::post('/order-tables/{orderTable}/close', [Api\OrderTableController::class, 'close'])->whereNumber('orderTable'); // #63
    });

    // owner / staff / admin（admin は ?store_id 必須）
    Route::middleware('role:owner,staff,admin')->group(function () {
        Route::get('/sales/{sale}', [Api\SaleController::class, 'show'])->whereNumber('sale');      // #7
        Route::get('/reports/daily', [Api\ReportController::class, 'daily']);                      // #9
        Route::get('/closings/{date}', [Api\ClosingController::class, 'show']);                     // #12
    });

    // owner / admin（admin は ?store_id 必須）
    Route::middleware('role:owner,admin')->group(function () {
        Route::get('/reports/summary', [Api\ReportController::class, 'summary']);                   // #10
        Route::get('/reports/export', [Api\ReportController::class, 'export']);                     // #11
        Route::get('/logs', [Api\AuditLogController::class, 'index']);                              // #42（admin の store_id は任意）
    });

    // owner のみ。/order は /{id} より先に登録し、ID は数値に限る（04 §4.9）
    Route::middleware('role:owner')->group(function () {
        // 商品
        Route::get('/products', [Api\ProductController::class, 'index']);                           // #14
        Route::post('/products', [Api\ProductController::class, 'store']);                          // #15
        Route::put('/products/order', [Api\ProductController::class, 'reorder']);                   // #19
        Route::post('/products/import', [Api\ProductImportController::class, 'store'])->middleware('throttle:import'); // #20
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

        // 店舗設定・税区分・支払方法
        Route::get('/settings/store', [Api\StoreSettingsController::class, 'show']);                // #30
        Route::put('/settings/store', [Api\StoreSettingsController::class, 'update']);              // #31
        Route::post('/tax-types', [Api\TaxTypeController::class, 'store']);                         // #32
        Route::put('/tax-types/order', [Api\TaxTypeController::class, 'reorder']);                  // #34
        Route::put('/tax-types/{taxType}', [Api\TaxTypeController::class, 'update'])->whereNumber('taxType');                 // #33
        Route::post('/payment-methods', [Api\PaymentMethodController::class, 'store']);             // #35
        Route::put('/payment-methods/order', [Api\PaymentMethodController::class, 'reorder']);      // #37
        Route::put('/payment-methods/{paymentMethod}', [Api\PaymentMethodController::class, 'update'])->whereNumber('paymentMethod'); // #36

        // スタッフ
        Route::get('/staff', [Api\StaffController::class, 'index']);                                // #38
        Route::post('/staff', [Api\StaffController::class, 'store']);                               // #39
        Route::put('/staff/{staff}', [Api\StaffController::class, 'update'])->whereNumber('staff');               // #40
        Route::put('/staff/{staff}/password', [Api\StaffController::class, 'updatePassword'])->whereNumber('staff'); // #41

        // テーブル・QR（12 §5.12）
        Route::post('/order-tables', [Api\OrderTableController::class, 'store']);                  // #57
        Route::put('/order-tables/{orderTable}', [Api\OrderTableController::class, 'update'])->whereNumber('orderTable');              // #58
        Route::delete('/order-tables/{orderTable}', [Api\OrderTableController::class, 'destroy'])->whereNumber('orderTable');          // #59
        Route::post('/order-tables/{orderTable}/token', [Api\OrderTableController::class, 'regenerateToken'])->whereNumber('orderTable'); // #60
        Route::get('/order-tables/{orderTable}/qr', [Api\OrderTableController::class, 'qr'])->whereNumber('orderTable');               // #61
    });

    // admin のみ
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/stores', [Api\Admin\StoreController::class, 'index']);                         // #43
        Route::patch('/stores/{store}/active', [Api\Admin\StoreController::class, 'updateActive'])->whereNumber('store'); // #44
        Route::get('/backup', [Api\Admin\BackupController::class, 'download'])
            ->middleware('throttle:backup');                                                        // #45
    });
});
