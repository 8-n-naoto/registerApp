<?php

namespace App\Http\Requests;

use App\Rules\NoControlCharacters;
use App\Rules\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 06 §11.4 POST /admin/stores。店舗名とオーナーのアカウント（store:create と同じ規則）。
 * パスワードの項目名は password のまま使う（TrimStrings が前後の空白を残すのはこの名前だけのため）
 */
class AdminStoreRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', new NoControlCharacters],
            'owner_login_id' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'login_id')],
            'owner_name' => ['required', 'string', 'max:50', new NoControlCharacters],
            'password' => ['required', ...PasswordRules::rules(), 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => '店舗名',
            'owner_login_id' => 'オーナーのログイン ID',
            'owner_name' => 'オーナーの表示名',
            'password' => 'オーナーの初期パスワード',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'owner_login_id.regex' => 'ログイン ID は半角英数字と _ . - で入力してください',
            'owner_login_id.unique' => 'このログイン ID は使用できません',
        ];
    }
}
