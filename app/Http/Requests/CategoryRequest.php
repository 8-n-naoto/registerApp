<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 06 §7.8 POST /categories・PUT /categories/{id}。名前は自店舗の（削除されていない）カテゴリと重複不可 */
class CategoryRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $current = $this->route('category');
        $unique = Rule::unique('categories', 'name')
            ->where('store_id', app(CurrentStore::class)->requireId())
            ->whereNull('deleted_at');
        if ($current instanceof Category) {
            $unique->ignore($current->id);
        }

        return [
            'name' => ['required', 'string', 'min:1', 'max:30', $unique],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'カテゴリ名'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => '同じ名前のカテゴリがあります'];
    }
}
