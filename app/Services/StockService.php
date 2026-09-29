<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * 在庫の増減（07 §5）。増減は条件付き UPDATE で行い、0 未満にしない。
 * 会計確定の減算・取消の戻しは WP 3 で追加する。
 */
final class StockService
{
    public const MAX_QTY = 999_999;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * 06 §7.5 PATCH /products/{id}/stock。mode = set（値に置き換え）/ add（加減算）
     */
    public function adjust(Product $product, string $mode, int $value): Product
    {
        return DB::transaction(function () use ($product, $mode, $value): Product {
            $before = (int) Product::query()->whereKey($product->id)->value('stock_qty');

            if ($mode === 'set') {
                Product::query()->whereKey($product->id)->update(['stock_qty' => $value]);
            } elseif (! $this->add($product, $value)) {
                $message = $value < 0
                    ? '在庫数が 0 未満になるため変更できません'
                    : '在庫数は '.number_format(self::MAX_QTY).' 以下にしてください';
                throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ['value' => [$message]]);
            }

            $product->refresh()->load('options');
            $this->audit->log(
                AuditAction::ProductStockChanged,
                $product,
                ['stock_qty' => $before],
                ['stock_qty' => $product->stock_qty],
            );

            return $product;
        });
    }

    /** 条件付き UPDATE。結果が 0〜MAX_QTY に収まる場合だけ更新して true */
    private function add(Product $product, int $value): bool
    {
        $query = Product::query()->whereKey($product->id);

        $affected = $value >= 0
            ? $query->where('stock_qty', '<=', self::MAX_QTY - $value)->increment('stock_qty', $value)
            : $query->where('stock_qty', '>=', -$value)->decrement('stock_qty', -$value);

        return $affected > 0;
    }
}
