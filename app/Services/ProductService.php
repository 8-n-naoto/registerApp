<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\OptionSelection;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 商品とオプションの登録・変更・削除・並び替え（06 §7.2〜7.6・§7.9）とオプションのグループ（docs/10「オプションのグループ」）。
 * 店舗の範囲は BelongsToStore のスコープで決まる。
 *
 * @phpstan-import-type OptionData from \App\Http\Requests\ProductOptionRequest
 */
final class ProductService
{
    public const MAX_PRODUCTS = 500;

    public const MAX_OPTIONS = 10;

    public const MAX_OPTION_GROUPS = 3;

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

            return $product->load(['options', 'optionGroups']);
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
                && (ProductOption::query()->where('product_id', $product->id)->exists()
                    || ProductOptionGroup::query()->where('product_id', $product->id)->exists())) {
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

            return $product->load(['options', 'optionGroups']);
        }));
    }

    /** 論理削除（オプションとそのグループも論理削除） */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            ProductOption::query()->where('product_id', $product->id)->delete();
            ProductOptionGroup::query()->where('product_id', $product->id)->delete();
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
     * @param  OptionData  $data
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
            $this->applyGroup($option, $data);
            $option->sort_order = SortOrder::next($scope);
            $option->save();
            $this->keepSingleDefault($option);

            $this->audit->log(AuditAction::OptionCreated, $option, null, $option->only(['product_id', 'name', 'price', 'is_active', 'group_id', 'is_default']));

            return $option;
        });
    }

    /**
     * @param  OptionData  $data
     */
    public function updateOption(ProductOption $option, array $data): ProductOption
    {
        return DB::transaction(function () use ($option, $data): ProductOption {
            $original = $option->attributesToArray();
            $option->fill($data);
            $this->applyGroup($option, $data);
            $option->save();
            $this->keepSingleDefault($option);

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

    /**
     * @param  array{name: string, selection: OptionSelection}  $data
     */
    public function createOptionGroup(Product $product, array $data): ProductOptionGroup
    {
        return DB::transaction(function () use ($product, $data): ProductOptionGroup {
            if ($product->is_discount) {
                $this->limitExceeded('name', '割引の商品にはオプションを付けられません');
            }
            $scope = ProductOptionGroup::query()->where('product_id', $product->id);
            if ($scope->count() >= self::MAX_OPTION_GROUPS) {
                $this->limitExceeded('name', 'オプションのグループは 1 商品につき '.self::MAX_OPTION_GROUPS.' 件までです');
            }

            $group = new ProductOptionGroup($data);
            $group->forceFill(['store_id' => $product->store_id, 'product_id' => $product->id]);
            $group->sort_order = SortOrder::next($scope);
            $group->save();

            $this->audit->log(AuditAction::OptionGroupCreated, $group, null, $this->groupSnapshot($group));

            return $group;
        });
    }

    /**
     * 「いくつでも」に変えた場合は「最初に選ぶ」を外す
     *
     * @param  array{name: string, selection: OptionSelection}  $data
     */
    public function updateOptionGroup(ProductOptionGroup $group, array $data): ProductOptionGroup
    {
        return DB::transaction(function () use ($group, $data): ProductOptionGroup {
            $before = $this->groupSnapshot($group);
            $group->fill($data)->save();
            if ($group->selection === OptionSelection::Multi) {
                ProductOption::query()->where('group_id', $group->id)->where('is_default', true)->update(['is_default' => false]);
            }

            $after = $this->groupSnapshot($group);
            $changed = array_flip(array_keys(array_diff_assoc($after, $before)));
            if ($changed !== []) {
                $this->audit->log(AuditAction::OptionGroupUpdated, $group,
                    array_intersect_key($before, $changed), array_intersect_key($after, $changed));
            }

            return $group;
        });
    }

    /** グループの中のオプションも論理削除する */
    public function deleteOptionGroup(ProductOptionGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            ProductOption::query()->where('group_id', $group->id)->delete();
            $group->delete();
            $this->audit->log(AuditAction::OptionGroupDeleted, $group, ['product_id' => $group->product_id, 'name' => $group->name], null);
        });
    }

    /**
     * グループは同じ商品のものだけ。「最初に選ぶ」は「1つ選ぶ」グループのオプションだけに付けられる。
     * 省略した項目は今のまま（「いくつでも」のグループやグループなしに移すと「最初に選ぶ」は外れる）
     *
     * @param  OptionData  $data
     */
    private function applyGroup(ProductOption $option, array $data): void
    {
        $groupId = array_key_exists('group_id', $data) ? $data['group_id'] : $option->group_id;
        $group = null;
        if ($groupId !== null) {
            $group = ProductOptionGroup::query()->where('product_id', $option->product_id)->find($groupId);
            if ($group === null) {
                $this->limitExceeded('group_id', 'そのグループは選べません');
            }
        }
        $single = $group?->selection === OptionSelection::Single;
        $isDefault = $data['is_default'] ?? ($single && $option->is_default);
        if ($isDefault && ! $single) {
            $this->limitExceeded('is_default', '「最初に選ぶ」は「1つ選ぶ」グループのオプションだけに付けられます');
        }

        $option->group_id = $group?->id;
        $option->is_default = $isDefault;
    }

    /** 「最初に選ぶ」はグループに 1 つだけ。今付けたものを残し、ほかは外す */
    private function keepSingleDefault(ProductOption $option): void
    {
        if ($option->is_default && $option->group_id !== null) {
            ProductOption::query()->where('group_id', $option->group_id)->whereKeyNot($option->id)
                ->where('is_default', true)->update(['is_default' => false]);
        }
    }

    /** @return array{product_id: int, name: string, selection: string} */
    private function groupSnapshot(ProductOptionGroup $group): array
    {
        return ['product_id' => $group->product_id, 'name' => $group->name, 'selection' => $group->selection->value];
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
