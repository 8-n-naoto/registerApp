<?php

namespace App\Http\Requests;

use App\Rules\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/** 06 §9 PUT /staff/{id}/password */
class StaffPasswordRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['password' => ['required', ...PasswordRules::rules()]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => '新しいパスワード'];
    }
}
