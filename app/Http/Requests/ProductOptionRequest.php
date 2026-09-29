<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 06 §7.9 POST /products/{id}/options・PUT /options/{id}。PUT は全項目の置き換え */
class ProductOptionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:30'],
            'price' => ['required', 'integer', 'min:-999999', 'max:999999'],
            'is_active' => [$this->isMethod('POST') ? 'sometimes' : 'required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'オプション名', 'price' => '価格', 'is_active' => '選択可'];
    }

    /** @return array{name: string, price: int, is_active: bool} */
    public function optionData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'price' => $this->integer('price'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
