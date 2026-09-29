<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** カテゴリの登録・変更・削除・並び替え（06 §7.8） */
final class CategoryService
{
    public const MAX_CATEGORIES = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name): Category
    {
        return DB::transaction(function () use ($name): Category {
            if (Category::query()->count() >= self::MAX_CATEGORIES) {
                $message = 'カテゴリは '.self::MAX_CATEGORIES.' 件までです';
                throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ['name' => [$message]]);
            }

            $category = new Category(['name' => $name]);
            $category->sort_order = SortOrder::next(Category::query());
            $category->save();

            $this->audit->log(AuditAction::CategoryCreated, $category, null, ['name' => $name]);

            return $category->loadCount('products');
        });
    }

    public function rename(Category $category, string $name): Category
    {
        return DB::transaction(function () use ($category, $name): Category {
            $before = $category->name;
            $category->name = $name;
            $category->save();

            if ($before !== $name) {
                $this->audit->log(AuditAction::CategoryUpdated, $category, ['name' => $before], ['name' => $name]);
            }

            return $category->loadCount('products');
        });
    }

    /** 所属商品（削除済みを含む）の category_id を NULL にしてから論理削除 */
    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            Product::withTrashed()->where('category_id', $category->id)->update(['category_id' => null]);
            $category->delete();
            $this->audit->log(AuditAction::CategoryDeleted, $category, ['name' => $category->name], null);
        });
    }

    /** @param  list<int>  $ids */
    public function reorder(array $ids): void
    {
        DB::transaction(fn () => SortOrder::apply(Category::query(), $ids));
    }
}
