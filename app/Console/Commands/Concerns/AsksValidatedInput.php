<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * 対話式コマンドで、検証に通るまで聞き直す。パスワードは画面に表示せず、確認のため 2 回入力させる
 *
 * @phpstan-require-extends Command
 */
trait AsksValidatedInput
{
    /** @param  list<mixed>  $rules */
    protected function askValid(string $question, string $attribute, array $rules): string
    {
        while (true) {
            $value = trim((string) $this->ask($question));
            $error = $this->firstError($value, $attribute, $rules);
            if ($error === null) {
                return $value;
            }
            $this->error($error);
        }
    }

    protected function askNewPassword(): string
    {
        while (true) {
            $password = (string) $this->secret('パスワード（8〜72 文字。画面に表示されません）');
            $error = $this->firstError($password, 'パスワード', ['required', 'string', 'min:8', 'max:72']);
            if ($error !== null) {
                $this->error($error);

                continue;
            }
            if ($password !== (string) $this->secret('確認のためもう一度')) {
                $this->error('パスワードが一致しません');

                continue;
            }

            return $password;
        }
    }

    /** @param  list<mixed>  $rules */
    private function firstError(string $value, string $attribute, array $rules): ?string
    {
        $validator = Validator::make(['value' => $value], ['value' => $rules], [], ['value' => $attribute]);

        return $validator->fails() ? $validator->errors()->first('value') : null;
    }
}
