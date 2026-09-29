<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderRequest;
use App\Http\Requests\TaxTypeRequest;
use App\Http\Resources\TaxTypeResource;
use App\Models\TaxType;
use App\Services\TaxTypeService;
use Illuminate\Http\Response;

class TaxTypeController extends Controller
{
    public function __construct(private readonly TaxTypeService $taxTypes) {}

    /** #32 POST /tax-types（06 §8.3） */
    public function store(TaxTypeRequest $request): TaxTypeResource
    {
        return TaxTypeResource::make($this->taxTypes->create($request->taxTypeData()));
    }

    /** #33 PUT /tax-types/{id} */
    public function update(TaxTypeRequest $request, TaxType $taxType): TaxTypeResource
    {
        return TaxTypeResource::make($this->taxTypes->update($taxType, [
            ...$request->taxTypeData(),
            'is_active' => $request->boolean('is_active'),
        ]));
    }

    /** #34 PUT /tax-types/order */
    public function reorder(ReorderRequest $request): Response
    {
        $this->taxTypes->reorder($request->ids());

        return response()->noContent();
    }
}
