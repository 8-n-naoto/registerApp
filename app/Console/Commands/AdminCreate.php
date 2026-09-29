<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AsksValidatedInput;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 05 §6.1・11 §3：admin を作る（システム全体で 1 人。既にいればエラー）
 */
class AdminCreate extends Command
{
    use AsksValidatedInput;

    protected $signature = 'admin:create';

    protected $description = '管理者（admin）を登録する（対話式・最初に 1 回だけ）';

    public function handle(): int
    {
        if (User::query()->where('role', Role::Admin)->exists()) {
            $this->error('admin は既に登録されています（システム全体で 1 人だけです）');

            return self::FAILURE;
        }

        $loginId = $this->askValid('ログイン ID（3〜50 文字の半角英数字と _ . -）', 'ログイン ID', [
            'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:users,login_id',
        ]);
        $name = $this->askValid('表示名', '表示名', ['required', 'string', 'max:50']);
        $password = $this->askNewPassword();

        $admin = new User(['login_id' => $loginId, 'name' => $name, 'password' => $password, 'is_active' => true]);
        $admin->forceFill(['role' => Role::Admin, 'store_id' => null])->save();

        $this->info("登録しました：admin #{$admin->id}（{$admin->login_id}）");

        return self::SUCCESS;
    }
}
