<?php

namespace App\Http\Requests;

use App\Enums\ProductColor;
use App\Services\StockService;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 06 §7.2 POST /products・§7.3 PUT /products/{id}。
 * PUT は全項目の置き換え（部分更新は不可）のため、POST で省略できる項目も必須にする。在庫数は PUT では受け取らない（§7.5）
 */
class ProductRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $required = $isCreate ? 'sometimes' : 'required';

        $rules = [
            'name' => ['required', 'string', 'min:1', 'max:50'],
            'price' => ['required', 'integer', 'min:0', 'max:9999999'],
            'category_id' => [$isCreate ? 'nullable' : 'present', 'nullable', 'integer', Rule::exists('categories', 'id')
                ->where('store_id', app(CurrentStore::class)->requireId())
                ->whereNull('deleted_at')],
            'color' => [$required, Rule::enum(ProductColor::class)],
            'is_active' => [$required, 'boolean'],
            'track_stock' => [$required, 'boolean'],
        ];
        if ($isCreate) {
            $rules['stock_qty'] = ['sometimes', 'integer', 'min:0', 'max:'.StockService::MAX_QTY];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => '商品名',
            'price' => '価格',
            'category_id' => 'カテゴリ',
            'color' => '色',
            'is_active' => '販売中',
            'track_stock' => '在庫管理',
            'stock_qty' => '在庫数',
        ];
    }

    /** @return array{name: string, price: int, category_id: int|null, color: string, is_active: bool, track_stock: bool} */
    public function productData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'price' => $this->integer('price'),
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'color' => $this->string('color', ProductColor::Gray->value)->toString(),
            'is_active' => $this->boolean('is_active', true),
            'track_stock' => $this->boolean('track_stock'),
        ];
    }

    public function stockQty(): int
    {
        return $this->integer('stock_qty');
    }
}
