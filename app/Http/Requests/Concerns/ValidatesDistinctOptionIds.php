<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * 06 §4.2・12 §5.2：明細の option_ids は「同じ行の中で」重複不可。
 * 規則の distinct は items.*.option_ids.* の全行をまとめて比べ、別の行に同じオプションがあるだけで 422 になるため、
 * 行ごとに調べる（F-006）
 */
trait ValidatesDistinctOptionIds
{
    /** @return callable(Validator): void */
    protected function distinctOptionIdsPerItem(): callable
    {
        return function (Validator $validator): void {
            $items = $this->input('items');
            if (! is_array($items)) {
                return;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || ! is_array($item['option_ids'] ?? null)) {
                    continue;
                }
                $seen = [];
                foreach ($item['option_ids'] as $j => $id) {
                    // 整数でない値は integer の規則が 422 にする
                    $value = filter_var($id, FILTER_VALIDATE_INT);
                    if ($value === false) {
                        continue;
                    }
                    if (isset($seen[$value])) {
                        $validator->errors()->add("items.{$i}.option_ids.{$j}", 'オプションが重複しています');
                    }
                    $seen[$value] = true;
                }
            }
        };
    }
}
