<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 13 §3.3 打刻の行（出勤〜退勤の 1 区間）。business_date は 'YYYY-MM-DD' の文字列のまま扱う。
 * 退勤が空で出勤から 16 時間以内なら勤務中、過ぎたら退勤未打刻（13 §2）
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property string $business_date
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property int|null $hourly_wage
 * @property int|null $edited_by
 * @property-read User $user
 * @property-read Collection<int, AttendanceBreak> $breaks
 */
class Attendance extends Model
{
    use BelongsToStore;

    /** 1 回の勤務の上限（分）。これを過ぎた退勤の無い行は退勤未打刻 */
    public const MAX_SHIFT_MINUTES = 16 * 60;

    protected $fillable = [
        'user_id',
        'business_date',
        'clock_in_at',
        'clock_out_at',
        'hourly_wage',
        'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'hourly_wage' => 'integer',
            'edited_by' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AttendanceBreak, $this> */
    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class)->orderBy('started_at')->orderBy('id');
    }

    /**
     * 勤務中（退勤が空で出勤から 16 時間以内）の行
     *
     * @param  Builder<self>  $query
     */
    public function scopeWorking(Builder $query): void
    {
        $query->whereNull('clock_out_at')
            ->where('clock_in_at', '>', now()->subMinutes(self::MAX_SHIFT_MINUTES));
    }

    public function isWorking(): bool
    {
        return $this->clock_out_at === null && $this->clock_in_at->gt(now()->subMinutes(self::MAX_SHIFT_MINUTES));
    }

    public function isStale(): bool
    {
        return $this->clock_out_at === null && ! $this->isWorking();
    }

    public function openBreak(): ?AttendanceBreak
    {
        return $this->breaks->first(fn (AttendanceBreak $b): bool => $b->ended_at === null);
    }

    /** 休憩の合計（分）。終わっていない休憩は $until（既定は今）まで数える */
    public function breakMinutes(?CarbonImmutable $until = null): int
    {
        $until ??= CarbonImmutable::now();
        $total = 0;
        foreach ($this->breaks as $b) {
            $end = $b->ended_at !== null ? CarbonImmutable::instance($b->ended_at) : $until;
            $total += self::minutesBetween(CarbonImmutable::instance($b->started_at), $end);
        }

        return $total;
    }

    /** 勤務時間（分）= 出勤〜退勤 − 休憩。退勤が空なら null */
    public function workMinutes(): ?int
    {
        if ($this->clock_out_at === null) {
            return null;
        }
        $out = CarbonImmutable::instance($this->clock_out_at);

        return max(0, self::minutesBetween(CarbonImmutable::instance($this->clock_in_at), $out) - $this->breakMinutes($out));
    }

    /** 分に切り捨てた時刻どうしの差（13 §6.3） */
    public static function minutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, intdiv($to->startOfMinute()->getTimestamp() - $from->startOfMinute()->getTimestamp(), 60));
    }
}
