<?php

namespace App\Rules;

use Closure;

/**
 * 新しいパスワードの規則（06 §3.4・§9、コマンドの admin:create・store:create）。4 か所の定義をここにまとめる（T004-08）。
 * bcrypt は先頭 72 バイトしか使わないため、上限は文字数ではなく UTF-8 のバイト数で見る（F-008）。
 * 画面側の同じ値は resources/js/lib/password.ts
 */
final class PasswordRules
{
    public const MIN = 8;

    public const MAX_BYTES = 72;

    /** @return list<mixed> */
    public static function rules(): array
    {
        return ['string', 'min:'.self::MIN, self::maxBytes()];
    }

    private static function maxBytes(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > self::MAX_BYTES) {
                $fail(':attributeは半角 '.self::MAX_BYTES.' 文字までにしてください（全角の文字は 1 文字で半角 3 文字分です）');
            }
        };
    }
}
