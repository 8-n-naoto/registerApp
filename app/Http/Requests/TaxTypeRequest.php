<?php

namespace App\Http\Requests;

use App\Models\TaxType;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 06 §8.3 POST /tax-types・PUT /tax-types/{id}。PUT は全項目必須 */
class TaxTypeRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $current = $this->route('taxType');
        $unique = Rule::unique('tax_types', 'name')->where('store_id', app(CurrentStore::class)->requireId());
        if ($current instanceof TaxType) {
            $unique->ignore($current->id);
        }
        $isUpdate = $this->isMethod('PUT');

        return [
            'name' => ['required', 'string', 'min:1', 'max:20', $unique],
            'rate_permille' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_default' => [$isUpdate ? 'required' : 'sometimes', 'boolean'],
            ...($isUpdate ? ['is_active' => ['required', 'boolean']] : []),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => '税区分名', 'rate_permille' => '税率', 'is_default' => '既定', 'is_active' => '有効'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => '同じ名前の税区分があります'];
    }

    /** @return array{name: string, rate_permille: int, is_default: bool} */
    public function taxTypeData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'rate_permille' => $this->integer('rate_permille'),
            'is_default' => $this->boolean('is_default'),
        ];
    }
}
