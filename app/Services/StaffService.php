<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Support\CurrentStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * スタッフの追加・変更・パスワード再設定（06 §9）。削除は無く停止だけ。
 * 上限の 20 人は有効なスタッフで数える（停止したスタッフは枠を使わない。再開するときにも数える）
 */
final class StaffService
{
    public const MAX_ACTIVE_STAFF = 20;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentStore $currentStore,
    ) {}

    /**
     * @param  array{login_id: string, name: string, password: string}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $storeId = $this->currentStore->requireId();
            $this->assertCapacity($storeId, 'login_id');

            $user = new User([...$data, 'is_active' => true]);
            $user->role = Role::Staff;
            $user->store_id = $storeId;
            $user->save();

            $this->audit->log(AuditAction::StaffCreated, $user, null, $user->only(['login_id', 'name', 'is_active']));

            return $user;
        });
    }

    /**
     * @param  array{name: string, is_active: bool}  $data
     */
    public function update(User $staff, array $data): User
    {
        return DB::transaction(function () use ($staff, $data): User {
            if ($data['is_active'] && ! $staff->is_active) {
                $this->assertCapacity((int) $staff->store_id, 'is_active');
            }

            $original = $staff->attributesToArray();
            $staff->fill($data)->save();

            [$before, $after] = AuditLogger::diffModel($original, $staff);
            if ($after !== []) {
                $this->audit->log(AuditAction::StaffUpdated, $staff, $before, $after);
            }

            return $staff;
        });
    }

    /** 「ログインを保持する」も無効にする。ほかの端末のセッションはパスワードのハッシュが変わるため切れる */
    public function resetPassword(User $staff, string $password): void
    {
        DB::transaction(function () use ($staff, $password): void {
            $staff->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $this->audit->log(AuditAction::StaffPasswordReset, $staff);
        });
    }

    private function assertCapacity(int $storeId, string $field): void
    {
        $active = User::query()
            ->where('store_id', $storeId)
            ->where('role', Role::Staff)
            ->where('is_active', true)
            ->count();
        if ($active >= self::MAX_ACTIVE_STAFF) {
            $message = '有効なスタッフは '.self::MAX_ACTIVE_STAFF.' 人までです';
            throw new BusinessException(ErrorCode::Validation, $message, 422, errors: [$field => [$message]]);
        }
    }
}
