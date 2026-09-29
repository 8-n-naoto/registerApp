<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** 制御文字（\x00-\x1F・\x7F）を含む文字列を拒否する。改行（\n・\r）は $allowNewline のときだけ許す（12 §7） */
final class NoControlCharacters implements ValidationRule
{
    public function __construct(private readonly bool $allowNewline = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        $pattern = $this->allowNewline ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($pattern, $value) === 1) {
            $fail(':attributeに使えない文字が含まれています');
        }
    }
}
