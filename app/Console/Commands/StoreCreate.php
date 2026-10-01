<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AsksValidatedInput;
use App\Enums\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 02 §4.5・11 §2.2：店舗とオーナーを登録する（docs/sql/create_store_owner.sql と同じ結果）。
 * 税区分・支払方法はオーナーの初回ログインで作られる（D-006）
 */
class StoreCreate extends Command
{
    use AsksValidatedInput;

    protected $signature = 'store:create';

    protected $description = '店舗とオーナーを登録する（対話式）';

    public function handle(): int
    {
        $storeName = $this->askValid('店舗名', '店舗名', ['required', 'string', 'max:100']);
        $loginId = $this->askValid('オーナーのログイン ID（3〜50 文字の半角英数字と _ . -）', 'ログイン ID', [
            'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:users,login_id',
        ]);
        $name = $this->askValid('オーナーの表示名', '表示名', ['required', 'string', 'max:50']);
        $password = $this->askNewPassword();

        [$store, $owner] = DB::transaction(function () use ($storeName, $loginId, $name, $password): array {
            $store = Store::query()->create(['name' => $storeName]);
            $owner = new User(['login_id' => $loginId, 'name' => $name, 'password' => $password, 'is_active' => true]);
            $owner->forceFill(['role' => Role::Owner, 'store_id' => $store->id, 'overtime_exempt' => true])->save();

            return [$store, $owner];
        });

        $this->info("登録しました：店舗 #{$store->id}「{$store->name}」、オーナー #{$owner->id}（{$owner->login_id}）");
        $this->line('税区分と支払方法は、オーナーが初めてログインしたときに自動で作られます。');

        return self::SUCCESS;
    }
}
