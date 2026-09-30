<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * @param  array{code: string, name: string, memo: string|null, price: int, category_id: int|null, color: string, is_active: bool, track_stock: bool, customer_visible?: bool, is_discount: bool, stock_qty: int}  $data
     */
    public function create(array $data): Product
    {
        return $this->guardCode(fn () => DB::transaction(function () use ($data): Product {
            if (Product::query()->count() >= self::MAX_PRODUCTS) {
                $this->limitExceeded('name', '商品は '.self::MAX_PRODUCTS.' 件までです');
            }

            $product = new Product($data);
            if ($product->is_discount) {
                $product->stock_qty = 0;
            }
            $product->sort_order = SortOrder::next($this->inCategory($data['category_id']));
            $product->save();

            $this->audit->log(AuditAction::ProductCreated, $product, null, $this->snapshot($product));

            return $product->load('options');
        }));
    }

    /**
     * 全項目の置き換え（在庫数を除く）。カテゴリを変えた場合は移動先の末尾に並べる
     *
     * @param  array{code: string, name: string, memo: string|null, price: int, category_id: int|null, color: string, is_active: bool, track_stock: bool, customer_visible?: bool, is_discount: bool}  $data
     */
    public function update(Product $product, array $data): Product
    {
        return $this->guardCode(fn () => DB::transaction(function () use ($product, $data): Product {
            $original = $product->attributesToArray();
            $product->fill($data);
            // 割引の明細はオプションを持てない（docs/10「割引の商品」。07 §1 の規則 3）
            if ($product->is_discount && $product->isDirty('is_discount')
                && ProductOption::query()->where('product_id', $product->id)->exists()) {
                $product->discardChanges();
                $this->limitExceeded('is_discount', 'オプションのある商品は割引にできません。先にオプションを削除してください');
            }
            if ($product->isDirty('category_id')) {
                $product->sort_order = SortOrder::next($this->inCategory($product->category_id));
            }
            $product->save();

            [$before, $after] = AuditLogger::diffModel($original, $product);
            if ($after !== []) {
                $this->audit->log(AuditAction::ProductUpdated, $product, $before, $after);
            }

            return $product->load('options');
        }));
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
            if ($product->is_discount) {
                $this->limitExceeded('name', '割引の商品にはオプションを付けられません');
            }
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
            'code' => $product->code,
            'name' => $product->name,
            'memo' => $product->memo,
            'price' => $product->price,
            'category_id' => $product->category_id,
            'color' => $product->color->value,
            'is_active' => $product->is_active,
            'track_stock' => $product->track_stock,
            'customer_visible' => $product->customer_visible,
            'is_discount' => $product->is_discount,
            'stock_qty' => $product->stock_qty,
        ];
    }

    /**
     * 検証の後に同じコードが登録された場合（同時操作）も、500 ではなく入力エラーにする
     *
     * @param  callable(): Product  $callback
     */
    private function guardCode(callable $callback): Product
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            $this->limitExceeded('code', 'その商品コードは既に使われています');
        }
    }

    private function limitExceeded(string $field, string $message): never
    {
        throw new BusinessException(ErrorCode::Validation, $message, 422, errors: [$field => [$message]]);
    }
}
