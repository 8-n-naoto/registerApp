<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrinterSettingsRequest;
use App\Http\Resources\StoreSettingsResource;
use App\Models\Store;
use App\Services\StoreSettingsService;
use App\Support\CurrentStore;

/** 15 §6 レシートプリンターの設定（#105、owner）。取得は店舗設定（StoreSettings.printer）に含める */
class PrinterSettingsController extends Controller
{
    public function __construct(private readonly CurrentStore $currentStore) {}

    /** #105 PUT /settings/printer。応答は更新後の StoreSettings */
    public function update(PrinterSettingsRequest $request, StoreSettingsService $settings): StoreSettingsResource
    {
        $store = Store::query()->findOrFail($this->currentStore->requireId());

        return StoreSettingsResource::make($settings->updatePrinter($store, $request->settings()));
    }
}
