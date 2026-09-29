<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToStore;
use Database\Factories\OrderTableFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * 12 §3.3。QR のトークンの平文は保存しない（検索は SHA-256、再印刷用に APP_KEY で暗号化した値だけ持つ）。
 * トークンの発行・作り直しは issueToken() で行う
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 * @property string $token_hash
 * @property string $token_encrypted
 * @property Carbon $token_rotated_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $deleted_at
 * @property int|null $unpaid_order_count withUnpaid() で付く
 * @property int|string|null $unpaid_subtotal withUnpaid() で付く（SUM の結果。0 件は null）
 */
class OrderTable extends Model
{
    use BelongsToStore;

    /** @use HasFactory<OrderTableFactory> */
    use HasFactory;

    use SoftDeletes;

    /** 1 店舗のテーブルの上限（12 §3.3） */
    public const MAX_PER_STORE = 100;

    /** トークンの形式（random_bytes(32) の base64url、パディングなし。12 §7） */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected $hidden = [
        'token_hash',
        'token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'token_rotated_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 新しいトークンを作って設定する（保存は呼び出し側）。平文のトークンを返す */
    public function issueToken(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->token_hash = self::hashToken($token);
        $this->token_encrypted = Crypt::encryptString($token);
        $this->token_rotated_at = Carbon::now();

        return $token;
    }

    /** QR の再印刷用に復号する */
    public function plainToken(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Order, $this> 未会計の注文（確認待ち・受付済みで sale_id が無い。12 §2.2） */
    public function unpaidOrders(): HasMany
    {
        return $this->orders()
            ->whereNull('sale_id')
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Active->value]);
    }

    /**
     * OrderTableResource の unpaid_order_count・unpaid_subtotal を 1 本のクエリの中の副問い合わせで付ける（12 §5.11、N+1 にしない）
     *
     * @param  Builder<OrderTable>  $query
     * @return Builder<OrderTable>
     */
    public function scopeWithUnpaid(Builder $query): Builder
    {
        return $query->withCount('unpaidOrders as unpaid_order_count')
            ->withSum('unpaidOrders as unpaid_subtotal', 'subtotal');
    }

    /** 利用中にしてからの受付時間（店舗の customer_session_minutes）が終わる日時。空席なら null */
    public function sessionExpiresAt(Store $store): ?Carbon
    {
        return $this->opened_at?->copy()->addMinutes($store->customer_session_minutes);
    }
}
