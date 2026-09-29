<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 06 §9 PUT /staff/{id}/password */
class StaffPasswordRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'min:8', 'max:72']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => '新しいパスワード'];
    }
}
