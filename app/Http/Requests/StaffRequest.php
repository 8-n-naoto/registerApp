<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 06 §9 POST /staff・PUT /staff/{id}。POST は login_id・name・password、PUT は name・is_active（全項目必須） */
class StaffRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if ($this->isMethod('PUT')) {
            return [
                'name' => ['required', 'string', 'min:1', 'max:50'],
                'is_active' => ['required', 'boolean'],
            ];
        }

        return [
            'login_id' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'login_id')],
            'name' => ['required', 'string', 'min:1', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['login_id' => 'ログイン ID', 'name' => '名前', 'password' => 'パスワード', 'is_active' => '有効'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'login_id.regex' => 'ログイン ID は半角英数字と _ . - で入力してください',
            'login_id.unique' => 'このログイン ID は使われています',
        ];
    }
}
