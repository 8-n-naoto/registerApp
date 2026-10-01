<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * 05 §3.2。BelongsToStore は付けない（ログインで全店舗から検索するため。staff の範囲は明示バインドで絞る）。
 * role と store_id は $fillable に入れず、作成する処理が明示して設定する。
 * hourly_wage・overtime_exempt は勤怠（13 §3.1）。owner だけが #80 で変える
 *
 * @property int $id
 * @property int|null $store_id
 * @property Role $role
 * @property string $login_id
 * @property string $name
 * @property string $password
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property int|null $hourly_wage
 * @property bool $overtime_exempt
 * @property string|null $remember_token
 * @property-read Store|null $store
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'login_id',
        'name',
        'password',
        'is_active',
        'hourly_wage',
        'overtime_exempt',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'hourly_wage' => 'integer',
            'overtime_exempt' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
