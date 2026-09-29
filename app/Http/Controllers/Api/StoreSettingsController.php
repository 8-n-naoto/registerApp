<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSettingsRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Http\Resources\StoreSettingsResource;
use App\Http\Resources\TaxTypeResource;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\TaxType;
use App\Services\StoreSettingsService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;

class StoreSettingsController extends Controller
{
    public function __construct(private readonly CurrentStore $currentStore) {}

    /** #30 GET /settings/store（06 §8.1）。税区分・支払方法は停止中も含む全件 */
    public function show(): JsonResponse
    {
        return response()->json([
            'store' => StoreSettingsResource::make($this->store()),
            'tax_types' => TaxTypeResource::collection(TaxType::query()->orderBy('sort_order')->orderBy('id')->get()),
            'payment_methods' => PaymentMethodResource::collection(
                PaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get(),
            ),
        ]);
    }

    /** #31 PUT /settings/store（06 §8.2） */
    public function update(StoreSettingsRequest $request, StoreSettingsService $settings): StoreSettingsResource
    {
        return StoreSettingsResource::make($settings->update($this->store(), $request->settings()));
    }

    private function store(): Store
    {
        return Store::query()->findOrFail($this->currentStore->requireId());
    }
}
