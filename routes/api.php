<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

// 06 §13 の 45 ルートと 12 §5.0 の #46〜#65 は各 WP で追加する（並びと権限は docs/04 §4.9）。

// 1 ─ 認証（未ログイン）。06 §3.1 により guest は付けない（ログイン中でも照合し、成功すれば切り替える）
Route::post('/login', [Api\AuthController::class, 'login']);                                        // #1

// 46〜48 ─ お客さんの公開 API（12 §5.1〜§5.3）。auth:sanctum を通さず、トークンだけでテーブルと店舗を決める。
// throttle:public-order を table.token より前に置き、無効なトークンへの要求も IP の回数制限に数える（12 §5.14）。
// {token} に形式の制約を付けない（形式違いも table.token で同じ 404 にし、回数制限に数えるため）
Route::middleware(['throttle:public-order', 'table.token'])->prefix('/public/tables/{token}')->group(function () {
    Route::get('/menu', [Api\PublicOrderController::class, 'menu']);                               // #46
    Route::post('/orders', [Api\PublicOrderController::class, 'store']);                           // #47
    Route::get('/orders', [Api\PublicOrderController::class, 'index']);                            // #48
});

// 66〜67 ─ 端末の担当者（13 §6.2）。ログアウト後も端末の店舗（セッション）で使うため auth:sanctum を通さない
Route::middleware('throttle:operators')->group(function () {
    Route::get('/operators', [Api\OperatorController::class, 'index']);                              // #66
    Route::post('/operators/switch', [Api\OperatorController::class, 'switch']);                     // #67
});

Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function () {

    // 2〜4 ─ 自分のアカウント（全役割）
    Route::post('/logout', [Api\AuthController::class, 'logout']);                                  // #2
    Route::get('/me', [Api\MeController::class, 'show']);                                           // #3
    Route::put('/me/password', [Api\MeController::class, 'updatePassword']);                        // #4

    // owner / staff（admin 不可）
    Route::middleware('role:owner,staff')->group(function () {
        Route::get('/register/bootstrap', [Api\RegisterController::class, 'bootstrap']);            // #5
        Route::post('/sales', [Api\SaleController::class, 'store']);                                // #6
        Route::post('/sales/offline', [Api\SaleController::class, 'storeOffline']);                  // #102（14 §5.1）
        Route::post('/sales/{sale}/cancel', [Api\SaleController::class, 'cancel'])->whereNumber('sale'); // #8
        Route::put('/closings/{date}', [Api\ClosingController::class, 'update']);                   // #13

        // 注文・厨房（12 §5.4〜§5.10）
        Route::get('/orders', [Api\OrderController::class, 'index']);                               // #49
        Route::post('/orders', [Api\OrderController::class, 'store']);                              // #50
        Route::post('/orders/{order}/accept', [Api\OrderController::class, 'accept'])->whereNumber('order');       // #51
        Route::post('/orders/{order}/cancel', [Api\OrderController::class, 'cancel'])->whereNumber('order');       // #52
        Route::post('/orders/{order}/serve-all', [Api\OrderController::class, 'serveAll'])->whereNumber('order');  // #53
        Route::patch('/order-items/{orderItem}/served', [Api\OrderController::class, 'served'])->whereNumber('orderItem'); // #54
        Route::get('/kitchen/orders', [Api\KitchenController::class, 'index']);                     // #55

        // テーブルの一覧・利用開始・終了（12 §5.11）
        Route::get('/order-tables', [Api\OrderTableController::class, 'index']);                   // #56
        Route::post('/order-tables/{orderTable}/open', [Api\OrderTableController::class, 'open'])->whereNumber('orderTable');   // #62
        Route::post('/order-tables/{orderTable}/close', [Api\OrderTableController::class, 'close'])->whereNumber('orderTable'); // #63

        // 打刻・勤怠・勤務表（13 §5）。staff は本人の勤怠と公開済みの勤務表だけ
        Route::post('/attendance/clock-in', [Api\AttendanceController::class, 'clockIn']);            // #68
        Route::post('/attendance/break-start', [Api\AttendanceController::class, 'breakStart']);      // #69
        Route::post('/attendance/break-end', [Api\AttendanceController::class, 'breakEnd']);          // #70
        Route::get('/attendances', [Api\AttendanceController::class, 'index']);                       // #71
        Route::get('/shifts', [Api\ShiftController::class, 'index']);                                 // #81
        Route::get('/shift-requests/mine', [Api\ShiftController::class, 'myRequests']);               // #86
        Route::put('/shift-requests/mine', [Api\ShiftController::class, 'submitRequests']);           // #87
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
        Route::post('/products/{product}/option-groups', [Api\ProductOptionGroupController::class, 'store'])->whereNumber('product');    // #88
        Route::put('/option-groups/{optionGroup}', [Api\ProductOptionGroupController::class, 'update'])->whereNumber('optionGroup');       // #89
        Route::delete('/option-groups/{optionGroup}', [Api\ProductOptionGroupController::class, 'destroy'])->whereNumber('optionGroup');  // #90

        // 店舗設定・税区分・支払方法
        Route::get('/settings/store', [Api\StoreSettingsController::class, 'show']);                // #30
        Route::put('/settings/store', [Api\StoreSettingsController::class, 'update']);              // #31
        Route::post('/tax-types', [Api\TaxTypeController::class, 'store']);                         // #32
        Route::put('/tax-types/order', [Api\TaxTypeController::class, 'reorder']);                  // #34
        Route::put('/tax-types/{taxType}', [Api\TaxTypeController::class, 'update'])->whereNumber('taxType');                 // #33
        Route::post('/payment-methods', [Api\PaymentMethodController::class, 'store']);             // #35
        Route::put('/payment-methods/order', [Api\PaymentMethodController::class, 'reorder']);      // #37
        Route::put('/payment-methods/{paymentMethod}', [Api\PaymentMethodController::class, 'update'])->whereNumber('paymentMethod'); // #36

        // 注文の設定（12 §5.13）
        Route::get('/settings/orders', [Api\OrderSettingsController::class, 'show']);              // #64
        Route::put('/settings/orders', [Api\OrderSettingsController::class, 'update']);            // #65

        // スタッフ
        Route::get('/staff', [Api\StaffController::class, 'index']);                                // #38
        Route::post('/staff', [Api\StaffController::class, 'store']);                               // #39
        Route::put('/staff/{staff}', [Api\StaffController::class, 'update'])->whereNumber('staff');               // #40
        Route::put('/staff/{staff}/password', [Api\StaffController::class, 'updatePassword'])->whereNumber('staff'); // #41

        // 勤怠の修正・集計、労働条件、勤務表の作成（13 §5）。/summary・/export は /{id} より先
        Route::post('/attendances', [Api\AttendanceController::class, 'store']);                      // #72
        Route::get('/attendances/summary', [Api\AttendanceController::class, 'summary']);             // #75
        Route::get('/attendances/export', [Api\AttendanceController::class, 'export']);               // #76
        Route::put('/attendances/{attendance}', [Api\AttendanceController::class, 'update'])->whereNumber('attendance');     // #73
        Route::delete('/attendances/{attendance}', [Api\AttendanceController::class, 'destroy'])->whereNumber('attendance'); // #74
        Route::get('/settings/labor', [Api\LaborController::class, 'showSettings']);                  // #77
        Route::put('/settings/labor', [Api\LaborController::class, 'updateSettings']);                // #78
        Route::get('/labor-members', [Api\LaborController::class, 'members']);                        // #79
        Route::put('/labor-members/{member}', [Api\LaborController::class, 'updateMember'])->whereNumber('member'); // #80
        Route::put('/shift-months', [Api\ShiftController::class, 'updateMonth']);                     // #82
        Route::post('/shifts', [Api\ShiftController::class, 'store']);                                // #83
        Route::put('/shifts/{shift}', [Api\ShiftController::class, 'update'])->whereNumber('shift');      // #84
        Route::delete('/shifts/{shift}', [Api\ShiftController::class, 'destroy'])->whereNumber('shift');  // #85
        Route::get('/shift-patterns', [Api\ShiftPatternController::class, 'index']);                       // #91
        Route::post('/shift-patterns', [Api\ShiftPatternController::class, 'store']);                      // #92
        Route::put('/shift-patterns/{shiftPattern}', [Api\ShiftPatternController::class, 'update'])->whereNumber('shiftPattern'); // #93

        // オフライン会計の確認（14 §5.2・§5.3）
        Route::get('/sales/offline-issues', [Api\SaleController::class, 'offlineIssues']);           // #103
        Route::post('/sales/{sale}/offline-review', [Api\SaleController::class, 'offlineReview'])->whereNumber('sale'); // #104

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
