<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\ReorderRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /** #14 GET /products（06 §7.1）。販売停止も含め、削除されていないもの全て */
    public function index(): JsonResponse
    {
        $categories = Category::query()->withCount('products')->orderBy('sort_order')->orderBy('id')->get();
        $products = Product::query()->with(['options', 'optionGroups'])->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'categories' => CategoryResource::collection($categories),
            'products' => ProductResource::collection($products),
        ]);
    }

    /** #15 POST /products（06 §7.2） */
    public function store(ProductRequest $request): ProductResource
    {
        return ProductResource::make($this->products->create(
            [...$request->productData(), 'stock_qty' => $request->stockQty()],
        ));
    }

    /** #16 PUT /products/{id}（06 §7.3） */
    public function update(ProductRequest $request, Product $product): ProductResource
    {
        return ProductResource::make($this->products->update($product, $request->productData()));
    }

    /** #17 DELETE /products/{id}（06 §7.4） */
    public function destroy(Product $product): Response
    {
        $this->products->delete($product);

        return response()->noContent();
    }

    /** #19 PUT /products/order（06 §7.6） */
    public function reorder(ReorderRequest $request): Response
    {
        $this->products->reorder($request->ids());

        return response()->noContent();
    }
}
