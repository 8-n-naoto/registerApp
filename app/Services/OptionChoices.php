<?php

namespace App\Services;

use App\Enums\ErrorCode;
use App\Enums\OptionSelection;
use App\Exceptions\BusinessException;
use App\Models\ProductOption;

/** オプションのグループの選び方の検証（docs/10「オプションのグループ」）。会計と注文で共通 */
final class OptionChoices
{
    /**
     * 「1つ選ぶ」グループから 2 つ以上選んだ明細があれば、その明細のオプション欄に 422。
     * 選ばないことは画面で防ぐ（サーバーでは許す）
     *
     * @param  list<list<int>>  $optionIdsPerItem  明細ごとの option_ids
     * @param  array<int, ProductOption>  $options  group を読み込み済みで、すべての ID を含む
     */
    public static function assertValid(array $optionIdsPerItem, array $options): void
    {
        foreach ($optionIdsPerItem as $i => $optionIds) {
            $chosen = [];
            foreach ($optionIds as $optionId) {
                $group = $options[$optionId]->group;
                if ($group === null || $group->selection !== OptionSelection::Single) {
                    continue;
                }
                if (isset($chosen[$group->id])) {
                    $message = "「{$group->name}」は 1 つだけ選べます";
                    throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ["items.{$i}.option_ids" => [$message]]);
                }
                $chosen[$group->id] = true;
            }
        }
    }
}
