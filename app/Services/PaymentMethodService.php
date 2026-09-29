<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/** 支払方法の追加・変更・並び替え（06 §8.4）。削除は無く停止だけ */
final class PaymentMethodService
{
    public const MAX_PAYMENT_METHODS = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, is_cash: bool}  $data
     */
    public function create(array $data): PaymentMethod
    {
        return DB::transaction(function () use ($data): PaymentMethod {
            if (PaymentMethod::query()->count() >= self::MAX_PAYMENT_METHODS) {
                $this->invalid('name', '支払方法は '.self::MAX_PAYMENT_METHODS.' 件までです');
            }

            $method = new PaymentMethod([...$data, 'is_active' => true]);
            $method->sort_order = SortOrder::next(PaymentMethod::query());
            $method->save();

            $this->audit->log(AuditAction::PaymentMethodCreated, $method, null,
                $method->only(['name', 'is_cash', 'is_active']));

            return $method;
        });
    }

    /**
     * @param  array{name: string, is_cash: bool, is_active: bool}  $data
     */
    public function update(PaymentMethod $method, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($method, $data): PaymentMethod {
            if (! $data['is_active']
                && ! PaymentMethod::query()->whereKeyNot($method->id)->where('is_active', true)->exists()) {
                $this->invalid('is_active', '有効な支払方法が 1 件もなくなるため停止できません');
            }

            $original = $method->attributesToArray();
            $method->fill($data)->save();

            [$before, $after] = AuditLogger::diffModel($original, $method);
            if ($after !== []) {
                $this->audit->log(AuditAction::PaymentMethodUpdated, $method, $before, $after);
            }

            return $method;
        });
    }

    /** @param  list<int>  $ids */
    public function reorder(array $ids): void
    {
        DB::transaction(fn () => SortOrder::apply(PaymentMethod::query(), $ids));
    }

    private function invalid(string $field, string $message): never
    {
        throw new BusinessException(ErrorCode::Validation, $message, 422, errors: [$field => [$message]]);
    }
}
