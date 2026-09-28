<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 05 §3.12。追記のみ（更新・削除はしない）。
 * 作成するのは AuditLogger だけで、利用者の入力を直接渡さないため store_id / user_id も $fillable に入れる
 * （ログイン失敗・admin の操作など、CurrentStore と異なる店舗を明示して記録する必要がある）
 *
 * @property int $id
 * @property int|null $store_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id',
        'user_id',
        'action',
        'target_type',
        'target_id',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
