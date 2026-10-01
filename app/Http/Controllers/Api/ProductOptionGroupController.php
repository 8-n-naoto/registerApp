<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductOptionGroupRequest;
use App\Http\Resources\ProductOptionGroupResource;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Services\ProductService;
use Illuminate\Http\Response;

/** オプションのグループ（docs/10「オプションのグループ」。#88〜#90） */
class ProductOptionGroupController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /** #88 POST /products/{id}/option-groups */
    public function store(ProductOptionGroupRequest $request, Product $product): ProductOptionGroupResource
    {
        return ProductOptionGroupResource::make($this->products->createOptionGroup($product, $request->groupData()));
    }

    /** #89 PUT /option-groups/{id} */
    public function update(ProductOptionGroupRequest $request, ProductOptionGroup $optionGroup): ProductOptionGroupResource
    {
        return ProductOptionGroupResource::make($this->products->updateOptionGroup($optionGroup, $request->groupData()));
    }

    /** #90 DELETE /option-groups/{id}（グループの中のオプションも削除） */
    public function destroy(ProductOptionGroup $optionGroup): Response
    {
        $this->products->deleteOptionGroup($optionGroup);

        return response()->noContent();
    }
}
