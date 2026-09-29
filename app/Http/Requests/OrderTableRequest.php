<?php

namespace App\Http\Requests;

use App\Models\OrderTable;
use App\Rules\NoControlCharacters;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 12 §5.12 POST /order-tables・PUT /order-tables/{id}。名前は自店舗の（削除されていない）テーブルと重複不可。
 * POST の sort_order は省略可（末尾に並べる）、PUT は name・sort_order・is_active のすべてが必須
 */
class OrderTableRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $current = $this->route('orderTable');
        $unique = Rule::unique('order_tables', 'name')
            ->where('store_id', app(CurrentStore::class)->requireId())
            ->whereNull('deleted_at');
        if ($current instanceof OrderTable) {
            $unique->ignore($current->id);
        }
        $updating = $current instanceof OrderTable;

        return [
            'name' => ['required', 'string', 'min:1', 'max:20', new NoControlCharacters, $unique],
            'sort_order' => [$updating ? 'required' : 'sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => $updating ? ['required', 'boolean'] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'テーブル名', 'sort_order' => '並び順', 'is_active' => '有効'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => '同じ名前のテーブルがあります'];
    }
}
