<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Requests\ReorderRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    /** #21 GET /categories（06 §7.8） */
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->withCount('products')->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /** #22 POST /categories */
    public function store(CategoryRequest $request): CategoryResource
    {
        return CategoryResource::make($this->categories->create($request->string('name')->toString()));
    }

    /** #23 PUT /categories/{id} */
    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        return CategoryResource::make($this->categories->rename($category, $request->string('name')->toString()));
    }

    /** #24 DELETE /categories/{id} */
    public function destroy(Category $category): Response
    {
        $this->categories->delete($category);

        return response()->noContent();
    }

    /** #25 PUT /categories/order */
    public function reorder(ReorderRequest $request): Response
    {
        $this->categories->reorder($request->ids());

        return response()->noContent();
    }
}
