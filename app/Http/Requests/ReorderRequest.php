<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 並び替え（06 §7.6・§7.8・§7.9・§8.3・§8.4）。ids の順に sort_order を振る */
class ReorderRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ids' => '並び順', 'ids.*' => '並び順'];
    }

    /** @return list<int> */
    public function ids(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('ids');

        return array_map(intval(...), $ids);
    }
}
