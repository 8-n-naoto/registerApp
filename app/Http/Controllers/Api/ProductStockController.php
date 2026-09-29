<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStockRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\StockService;

class ProductStockController extends Controller
{
    /** #18 PATCH /products/{id}/stock（06 §7.5） */
    public function update(UpdateStockRequest $request, Product $product, StockService $stock): ProductResource
    {
        return ProductResource::make($stock->adjust(
            $product,
            $request->string('mode')->toString(),
            $request->integer('value'),
        ));
    }
}
