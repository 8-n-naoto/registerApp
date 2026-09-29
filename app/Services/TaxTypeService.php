<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\TaxType;
use Illuminate\Support\Facades\DB;

/**
 * 税区分の追加・変更・並び替え（06 §8.3）。削除は無く停止だけ。
 * 既定（is_default）は店舗内でちょうど 1 件、かつ有効なものに限る。
 */
final class TaxTypeService
{
    public const MAX_TAX_TYPES = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, rate_permille: int, is_default: bool}  $data
     */
    public function create(array $data): TaxType
    {
        return DB::transaction(function () use ($data): TaxType {
            if (TaxType::query()->count() >= self::MAX_TAX_TYPES) {
                $this->invalid('name', '税区分は '.self::MAX_TAX_TYPES.' 件までです');
            }

            $taxType = new TaxType([...$data, 'is_active' => true]);
            $taxType->sort_order = SortOrder::next(TaxType::query());
            $taxType->save();
            if ($taxType->is_default) {
                $this->clearOtherDefaults($taxType);
            }

            $this->audit->log(AuditAction::TaxTypeCreated, $taxType, null,
                $taxType->only(['name', 'rate_permille', 'is_default', 'is_active']));

            return $taxType;
        });
    }

    /**
     * 停止すると既定は外れ、並び順で最初の有効な税区分が既定になる。
     * 有効な税区分が 0 件になる変更と、既定を他に移さずに外す変更は 422
     *
     * @param  array{name: string, rate_permille: int, is_active: bool, is_default: bool}  $data
     */
    public function update(TaxType $taxType, array $data): TaxType
    {
        return DB::transaction(function () use ($taxType, $data): TaxType {
            $others = TaxType::query()->whereKeyNot($taxType->id);
            if (! $data['is_active'] && ! (clone $others)->where('is_active', true)->exists()) {
                $this->invalid('is_active', '有効な税区分が 1 件もなくなるため停止できません');
            }
            if (! $data['is_active']) {
                $data['is_default'] = false;
            } elseif ($taxType->is_default && ! $data['is_default']) {
                $this->invalid('is_default', '既定を外すには、ほかの税区分を既定にしてください');
            }

            $original = $taxType->attributesToArray();
            $wasDefault = $taxType->is_default;
            $taxType->fill($data)->save();

            if ($taxType->is_default) {
                $this->clearOtherDefaults($taxType);
            } elseif ($wasDefault) {
                TaxType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                    ->firstOrFail()->update(['is_default' => true]);
            }

            [$before, $after] = AuditLogger::diffModel($original, $taxType);
            if ($after !== []) {
                $this->audit->log(AuditAction::TaxTypeUpdated, $taxType, $before, $after);
            }

            return $taxType;
        });
    }

    /** @param  list<int>  $ids */
    public function reorder(array $ids): void
    {
        DB::transaction(fn () => SortOrder::apply(TaxType::query(), $ids));
    }

    private function clearOtherDefaults(TaxType $taxType): void
    {
        TaxType::query()->whereKeyNot($taxType->id)->where('is_default', true)->update(['is_default' => false]);
    }

    private function invalid(string $field, string $message): never
    {
        throw new BusinessException(ErrorCode::Validation, $message, 422, errors: [$field => [$message]]);
    }
}
