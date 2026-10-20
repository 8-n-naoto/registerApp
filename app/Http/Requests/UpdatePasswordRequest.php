<?php

namespace App\Http\Requests;

use App\Rules\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/** 06 §3.4 */
class UpdatePasswordRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', ...PasswordRules::rules(), 'confirmed', 'different:current_password'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'current_password' => '現在のパスワード',
            'password' => '新しいパスワード',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.current_password' => '現在のパスワードが違います',
            'password.different' => '新しいパスワードは現在のパスワードと違うものにしてください',
        ];
    }
}
