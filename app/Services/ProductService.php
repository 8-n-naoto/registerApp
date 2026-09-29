<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 商品とオプションの登録・変更・削除・並び替え（06 §7.2〜7.6・§7.9）。
 * 店舗の範囲は BelongsToStore のスコープで決まる。
 */
final class ProductService
{
    public const MAX_PRODUCTS = 500;

    public const MAX_OPTIONS = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, price: int, category_id: int|null, color: string, is_active: bool, track_stock: bool, stock_qty: int}  $data
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            if (Product::query()->count() >= self::MAX_PRODUCTS) {
                $this->limitExceeded('name', '商品は '.self::MAX_PRODUCTS.' 件までです');
            }

            $product = new Product($data);
            $product->sort_order = SortOrder::next($this->inCategory($data['category_id']));
            $product->save();

            $this->audit->log(AuditAction::ProductCreated, $product, null, $this->snapshot($product));

            return $product->load('options');
        });
    }

    /**
     * 全項目の置き換え（在庫数を除く）。カテゴリを変えた場合は移動先の末尾に並べる
     *
     * @param  array{name: string, price: int, category_id: int|null, color: string, is_active: bool, track_stock: bool}  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $original = $product->attributesToArray();
            $product->fill($data);
            if ($product->isDirty('category_id')) {
                $product->sort_order = SortOrder::next($this->inCategory($product->category_id));
            }
            $product->save();

            [$before, $after] = AuditLogger::diffModel($original, $product);
            if ($after !== []) {
                $this->audit->log(AuditAction::ProductUpdated, $product, $before, $after);
            }

            return $product->load('options');
        });
    }

    /** 論理削除（オプションも論理削除） */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            ProductOption::query()->where('product_id', $product->id)->delete();
            $product->delete();
            $this->audit->log(AuditAction::ProductDeleted, $product, ['name' => $product->name], null);
        });
    }

    /** @param  list<int>  $ids */
    public function reorder(array $ids): void
    {
        DB::transaction(fn () => SortOrder::apply(Product::query(), $ids));
    }

    /**
     * @param  array{name: string, price: int, is_active: bool}  $data
     */
    public function createOption(Product $product, array $data): ProductOption
    {
        return DB::transaction(function () use ($product, $data): ProductOption {
            $scope = ProductOption::query()->where('product_id', $product->id);
            if ($scope->count() >= self::MAX_OPTIONS) {
                $this->limitExceeded('name', 'オプションは 1 商品につき '.self::MAX_OPTIONS.' 件までです');
            }

            $option = new ProductOption($data);
            $option->forceFill(['store_id' => $product->store_id, 'product_id' => $product->id]);
            $option->sort_order = SortOrder::next($scope);
            $option->save();

            $this->audit->log(AuditAction::OptionCreated, $option, null, $option->only(['product_id', 'name', 'price', 'is_active']));

            return $option;
        });
    }

    /**
     * @param  array{name: string, price: int, is_active: bool}  $data
     */
    public function updateOption(ProductOption $option, array $data): ProductOption
    {
        return DB::transaction(function () use ($option, $data): ProductOption {
            $original = $option->attributesToArray();
            $option->fill($data)->save();

            [$before, $after] = AuditLogger::diffModel($original, $option);
            if ($after !== []) {
                $this->audit->log(AuditAction::OptionUpdated, $option, $before, $after);
            }

            return $option;
        });
    }

    public function deleteOption(ProductOption $option): void
    {
        DB::transaction(function () use ($option): void {
            $option->delete();
            $this->audit->log(AuditAction::OptionDeleted, $option, $option->only(['product_id', 'name']), null);
        });
    }

    /** @param  list<int>  $ids  その商品のオプションの ID */
    public function reorderOptions(Product $product, array $ids): void
    {
        DB::transaction(fn () => SortOrder::apply(ProductOption::query()->where('product_id', $product->id), $ids));
    }

    /** @return Builder<Product> */
    private function inCategory(?int $categoryId): Builder
    {
        return $categoryId === null
            ? Product::query()->whereNull('category_id')
            : Product::query()->where('category_id', $categoryId);
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $product): array
    {
        return [
            'name' => $product->name,
            'price' => $product->price,
            'category_id' => $product->category_id,
            'color' => $product->color->value,
            'is_active' => $product->is_active,
            'track_stock' => $product->track_stock,
            'stock_qty' => $product->stock_qty,
        ];
    }

    private function limitExceeded(string $field, string $message): never
    {
        throw new BusinessException(ErrorCode::Validation, $message, 422, errors: [$field => [$message]]);
    }
}
