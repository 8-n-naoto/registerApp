<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSettingsRequest;
use App\Http\Resources\OrderSettingsResource;
use App\Models\Store;
use App\Services\StoreSettingsService;
use App\Support\CurrentStore;

/** 12 §5.13 注文の設定（#64・#65、owner） */
class OrderSettingsController extends Controller
{
    public function __construct(private readonly CurrentStore $currentStore) {}

    /** #64 GET /settings/orders */
    public function show(): OrderSettingsResource
    {
        return OrderSettingsResource::make($this->store());
    }

    /** #65 PUT /settings/orders */
    public function update(OrderSettingsRequest $request, StoreSettingsService $settings): OrderSettingsResource
    {
        return OrderSettingsResource::make($settings->updateOrderSettings($this->store(), $request->settings()));
    }

    private function store(): Store
    {
        return Store::query()->findOrFail($this->currentStore->requireId());
    }
}
