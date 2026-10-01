<?php

namespace App\Http\Requests;

use App\Enums\OptionSelection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /products/{id}/option-groups・PUT /option-groups/{id}（docs/10「オプションのグループ」）。PUT は全項目の置き換え */
class ProductOptionGroupRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:30'],
            'selection' => ['required', Rule::enum(OptionSelection::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'グループ名', 'selection' => '選び方'];
    }

    /** @return array{name: string, selection: OptionSelection} */
    public function groupData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'selection' => $this->enum('selection', OptionSelection::class) ?? OptionSelection::Multi,
        ];
    }
}
