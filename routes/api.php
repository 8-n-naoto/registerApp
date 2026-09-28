<?php

use Illuminate\Support\Facades\Route;

// 06 §13 の 45 ルートは各 WP で追加する（並びと権限は docs/04 §4.9）。
Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function () {
    //
});
