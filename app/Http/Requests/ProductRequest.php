<?php

namespace App\Http\Requests;

use App\Enums\ProductColor;
use App\Models\Product;
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
    /** 商品コードは全角を半角、英字を大文字にそろえてから検証する */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Product::normalizeCode($this->string('code')->toString())]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $required = $isCreate ? 'sometimes' : 'required';
        $storeId = app(CurrentStore::class)->requireId();
        $product = $this->route('product');

        $rules = [
            // POST は空欄なら自動採番。PUT は必須
            'code' => [$isCreate ? 'nullable' : 'required', 'string', 'max:20', 'regex:'.Product::CODE_PATTERN,
                Rule::unique('products', 'code')
                    ->where('store_id', $storeId)
                    ->whereNull('deleted_at')
                    ->ignore($product instanceof Product ? $product->id : null)],
            'name' => ['required', 'string', 'min:1', 'max:50'],
            'memo' => [$isCreate ? 'nullable' : 'present', 'nullable', 'string', 'max:200'],
            'price' => ['required', 'integer', 'min:0', 'max:9999999'],
            'category_id' => [$isCreate ? 'nullable' : 'present', 'nullable', 'integer', Rule::exists('categories', 'id')
                ->where('store_id', $storeId)
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
            'code' => '商品コード',
            'name' => '商品名',
            'memo' => 'メモ',
            'price' => '価格',
            'category_id' => 'カテゴリ',
            'color' => '色',
            'is_active' => '販売中',
            'track_stock' => '在庫管理',
            'stock_qty' => '在庫数',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => '商品コードは英数字・ハイフン・アンダースコアで入力してください',
        ];
    }

    /** @return array{code: string, name: string, memo: string|null, price: int, category_id: int|null, color: string, is_active: bool, track_stock: bool} */
    public function productData(): array
    {
        return [
            'code' => $this->filled('code') ? $this->string('code')->toString() : '',
            'name' => $this->string('name')->toString(),
            'memo' => $this->filled('memo') ? $this->string('memo')->toString() : null,
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
