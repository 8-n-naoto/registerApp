<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * 06 §1.8：処理と同じトランザクションの中で呼ぶ。before / after は変更のあった項目だけ。
 */
final class AuditLogger
{
    /** どこから渡されても記録しない列 */
    private const SECRET_KEYS = ['password', 'password_confirmation', 'current_password', 'remember_token'];

    public function __construct(
        private readonly Request $request,
        private readonly CurrentStore $currentStore,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  int|null  $storeId  明示する場合（ログイン・店舗の初期化・admin の店舗停止など）。省略時は対象または CurrentStore から
     */
    public function log(
        AuditAction $action,
        ?Model $target = null,
        ?array $before = null,
        ?array $after = null,
        ?int $storeId = null,
    ): AuditLog {
        $targetStoreId = $target?->getAttribute('store_id');
        $storeId ??= is_int($targetStoreId) ? $targetStoreId : $this->currentStore->id();
        $userId = $this->request->user()?->getAuthIdentifier();

        return AuditLog::query()->withoutGlobalScope('store')->create([
            'store_id' => $storeId,
            'user_id' => is_int($userId) ? $userId : null,
            'action' => $action->value,
            'target_type' => $target !== null ? Str::snake(class_basename($target)) : null,
            'target_id' => $target?->getKey(),
            'before' => $before !== null ? Arr::except($before, self::SECRET_KEYS) : null,
            'after' => $after !== null ? Arr::except($after, self::SECRET_KEYS) : null,
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 255, ''),
        ]);
    }

    /**
     * 変更のあった項目だけを取り出す。$model->getChanges() の前に getOriginal() を控えておき、両方を渡す。
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $changes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $original, array $changes): array
    {
        $keys = array_diff(array_keys($changes), ['updated_at', ...self::SECRET_KEYS]);

        return [Arr::only($original, $keys), Arr::only($changes, $keys)];
    }
}
