<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductOptionRequest;
use App\Http\Requests\ReorderRequest;
use App\Http\Resources\ProductOptionResource;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\ProductService;
use Illuminate\Http\Response;

class ProductOptionController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /** #26 POST /products/{id}/options（06 §7.9） */
    public function store(ProductOptionRequest $request, Product $product): ProductOptionResource
    {
        return ProductOptionResource::make($this->products->createOption($product, $request->optionData()));
    }

    /** #27 PUT /options/{id} */
    public function update(ProductOptionRequest $request, ProductOption $option): ProductOptionResource
    {
        return ProductOptionResource::make($this->products->updateOption($option, $request->optionData()));
    }

    /** #28 DELETE /options/{id} */
    public function destroy(ProductOption $option): Response
    {
        $this->products->deleteOption($option);

        return response()->noContent();
    }

    /** #29 PUT /products/{id}/options/order（その商品のオプションのみ） */
    public function reorder(ReorderRequest $request, Product $product): Response
    {
        $this->products->reorderOptions($product, $request->ids());

        return response()->noContent();
    }
}
