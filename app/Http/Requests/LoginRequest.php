<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 06 §3.1 */
class LoginRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'login_id' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'login_id' => 'ログイン ID',
            'password' => 'パスワード',
        ];
    }
}
