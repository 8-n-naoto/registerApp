<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 12 §3.4。status（受付）・served_at（提供）・sale_id（会計）は互いに独立に持つ（12 §2.2）。
 * business_date は 'YYYY-MM-DD' の文字列のまま扱う（Sale と同じ）
 *
 * @property int $id
 * @property int $store_id
 * @property string $client_uuid
 * @property string $business_date
 * @property int $order_no
 * @property OrderSource $source
 * @property int|null $order_table_id
 * @property string|null $table_name
 * @property string|null $label
 * @property OrderStatus $status
 * @property string|null $note
 * @property int $subtotal
 * @property Carbon|null $served_at
 * @property int|null $sale_id
 * @property int|null $user_id
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $device_name
 * @property Carbon $created_at
 */
class Order extends Model
{
    use BelongsToStore;

    /** OrderResource が使う関連 */
    public const WITH_ALL = ['items.options', 'user'];

    /** テーブルなしの注文の番号（attachTakeoutNo() が入れる。列ではない） */
    public ?int $takeoutNo = null;

    protected $fillable = [
        'client_uuid',
        'business_date',
        'order_no',
        'source',
        'order_table_id',
        'table_name',
        'label',
        'status',
        'note',
        'subtotal',
        'user_id',
        'device_name',
    ];

    protected function casts(): array
    {
        return [
            'order_no' => 'integer',
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'subtotal' => 'integer',
            'served_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * テーブルなしの注文の、営業日ごとの連番（1 から。取消・会計済みも数えるので番号は変わらない）。テーブルの注文は null。
     * 一覧では先に attachTakeoutNo() でまとめて入れておく（1 件ずつ数えない）
     */
    public function takeoutNo(): ?int
    {
        if ($this->order_table_id !== null) {
            return null;
        }
        if ($this->takeoutNo === null) {
            /** @var Collection<int, Order> $one */
            $one = new Collection([$this]);
            self::attachTakeoutNo($one);
        }

        return $this->takeoutNo;
    }

    /**
     * テーブルなしの注文に takeoutNo を 1 回のクエリで入れる。同じ店舗・営業日のテーブルなしの注文のうち、
     * ID がその注文以下のものの件数を番号にする（注文のテーブルは作成後に変わらない）
     *
     * @param  Collection<int, Order>  $orders
     */
    public static function attachTakeoutNo(Collection $orders): void
    {
        $targets = $orders->filter(fn (Order $order): bool => $order->order_table_id === null && $order->takeoutNo === null);
        if ($targets->isEmpty()) {
            return;
        }

        $numbers = DB::table('orders as o')
            ->whereIn('o.id', $targets->pluck('id')->all())
            ->select('o.id')
            ->selectSub(
                DB::table('orders as p')
                    ->whereColumn('p.store_id', 'o.store_id')
                    ->whereColumn('p.business_date', 'o.business_date')
                    ->whereNull('p.order_table_id')
                    ->whereColumn('p.id', '<=', 'o.id')
                    ->selectRaw('COUNT(*)'),
                'n',
            )
            ->pluck('n', 'id');

        foreach ($targets as $order) {
            $order->takeoutNo = (int) ($numbers[$order->id] ?? 0);
        }
    }
}
