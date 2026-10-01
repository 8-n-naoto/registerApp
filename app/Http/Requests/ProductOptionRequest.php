<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 06 §7.9 POST /products/{id}/options・PUT /options/{id}。PUT は全項目の置き換え。
 * group_id・is_default（docs/10「オプションのグループ」）は省略できる。POST で省略するとグループなし、PUT で省略すると今のまま
 *
 * @phpstan-type OptionData array{name: string, price: int, is_active: bool, group_id?: int|null, is_default?: bool}
 */
class ProductOptionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:30'],
            'price' => ['required', 'integer', 'min:-999999', 'max:999999'],
            'is_active' => [$this->isMethod('POST') ? 'sometimes' : 'required', 'boolean'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'オプション名', 'price' => '価格', 'is_active' => '選択可', 'group_id' => 'グループ', 'is_default' => '最初に選ぶ'];
    }

    /** @return OptionData */
    public function optionData(): array
    {
        $data = [
            'name' => $this->string('name')->toString(),
            'price' => $this->integer('price'),
            'is_active' => $this->boolean('is_active', true),
        ];
        if ($this->has('group_id')) {
            $data['group_id'] = $this->filled('group_id') ? $this->integer('group_id') : null;
        }
        if ($this->has('is_default')) {
            $data['is_default'] = $this->boolean('is_default');
        }

        return $data;
    }
}
