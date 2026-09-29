<?php

namespace App\Models;

use App\Enums\ProductColor;
use App\Models\Concerns\BelongsToStore;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 05 §3.6。在庫の増減は StockService の条件付き UPDATE で行う（07 §5）
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $category_id
 * @property string $code
 * @property string $name
 * @property string|null $memo
 * @property int $price
 * @property ProductColor $color
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $track_stock
 * @property int $stock_qty
 * @property bool $customer_visible
 */
class Product extends Model
{
    use BelongsToStore;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    /** 自動採番する商品コードの形式（P + 4 桁以上の連番） */
    public const AUTO_CODE_PREFIX = 'P';

    /** 商品コードに使える文字（正規化後。20 文字以内は検証側で見る） */
    public const CODE_PATTERN = '/^[A-Z0-9_-]+$/';

    /** DB の既定値（12 §3.7） */
    protected $attributes = [
        'customer_visible' => true,
    ];

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'memo',
        'price',
        'color',
        'sort_order',
        'is_active',
        'track_stock',
        'stock_qty',
        'customer_visible',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'color' => ProductColor::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'stock_qty' => 'integer',
            'customer_visible' => 'boolean',
        ];
    }

    /** 商品コードが空なら店舗内の次の番号を振る（画面・CSV・初期データのどこから作っても同じ規則にする） */
    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (($product->code ?? '') === '') {
                $product->code = self::nextAutoCode($product->store_id);
            }
        });
    }

    /**
     * P0001 形式の既存の最大番号 + 1。削除済みも含めて数え、番号を重ねない
     */
    public static function nextAutoCode(int $storeId): string
    {
        $max = 0;
        $codes = self::query()->withoutGlobalScopes()->where('store_id', $storeId)
            ->where('code', 'like', self::AUTO_CODE_PREFIX.'%')->pluck('code');
        foreach ($codes as $code) {
            if (preg_match('/^'.self::AUTO_CODE_PREFIX.'([0-9]{1,9})$/', (string) $code, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf(self::AUTO_CODE_PREFIX.'%04d', $max + 1);
    }

    /** 商品コードの入力を正規化する（全角英数字・記号を半角、英字を大文字） */
    public static function normalizeCode(string $value): string
    {
        return strtoupper(trim(mb_convert_kana($value, 'as', 'UTF-8')));
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
