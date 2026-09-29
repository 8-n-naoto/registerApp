<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use App\Support\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 06 §8.4 POST /payment-methods・PUT /payment-methods/{id}。PUT は全項目必須 */
class PaymentMethodRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $current = $this->route('paymentMethod');
        $unique = Rule::unique('payment_methods', 'name')->where('store_id', app(CurrentStore::class)->requireId());
        if ($current instanceof PaymentMethod) {
            $unique->ignore($current->id);
        }

        return [
            'name' => ['required', 'string', 'min:1', 'max:20', $unique],
            'is_cash' => ['required', 'boolean'],
            ...($this->isMethod('PUT') ? ['is_active' => ['required', 'boolean']] : []),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => '支払方法名', 'is_cash' => '現金として扱う', 'is_active' => '有効'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => '同じ名前の支払方法があります'];
    }
}
