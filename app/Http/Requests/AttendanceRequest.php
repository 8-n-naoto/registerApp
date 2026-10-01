<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Support\BusinessDate;
use App\Support\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 13 §5 #72・#73 owner による打刻の追加・修正。時刻は端末の入力どおり 'YYYY-MM-DDTHH:MM'（日本時間）
 */
class AttendanceRequest extends FormRequest
{
    public const FORMAT = 'Y-m-d\TH:i';

    /** 1 行の上限（24 時間） */
    private const MAX_MINUTES = 24 * 60;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'clock_in_at' => ['required', 'string', 'date_format:'.self::FORMAT],
            'clock_out_at' => ['present', 'nullable', 'string', 'date_format:'.self::FORMAT],
            'breaks' => ['present', 'array', 'max:10'],
            'breaks.*' => ['array:started_at,ended_at'],
            'breaks.*.started_at' => ['required', 'string', 'date_format:'.self::FORMAT],
            'breaks.*.ended_at' => ['present', 'nullable', 'string', 'date_format:'.self::FORMAT],
            'hourly_wage' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
        if ($this->isMethod('POST')) {
            $rules['user_id'] = ['required', 'integer', Rule::exists('users', 'id')
                ->where('store_id', app(CurrentStore::class)->requireId())
                ->whereIn('role', [Role::Owner->value, Role::Staff->value])];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $in = $this->clockIn();
            $out = $this->clockOut();
            if ($out !== null && $out->lte($in)) {
                $v->errors()->add('clock_out_at', '退勤は出勤より後にしてください');

                return;
            }
            $limit = $out ?? $in->addMinutes(self::MAX_MINUTES);
            if ($out !== null && $in->diffInMinutes($out) > self::MAX_MINUTES) {
                $v->errors()->add('clock_out_at', '1 回の勤務は 24 時間以内にしてください');

                return;
            }
            $prevEnd = $in;
            $breaks = $this->breakTimes();
            foreach ($breaks as $i => [$start, $end]) {
                $last = $i === count($breaks) - 1;
                if ($start->lt($prevEnd) || $start->gte($limit)) {
                    $v->errors()->add("breaks.$i.started_at", '休憩は勤務の時間内で、重ならないように入れてください');

                    return;
                }
                if ($end === null && ! ($out === null && $last)) {
                    $v->errors()->add("breaks.$i.ended_at", '休憩の終了を入れてください');

                    return;
                }
                if ($end !== null && ($end->lte($start) || $end->gt($limit))) {
                    $v->errors()->add("breaks.$i.ended_at", '休憩の終了は開始より後、退勤より前にしてください');

                    return;
                }
                $prevEnd = $end ?? $limit;
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'user_id' => '従業員',
            'clock_in_at' => '出勤',
            'clock_out_at' => '退勤',
            'breaks' => '休憩',
            'breaks.*.started_at' => '休憩の開始',
            'breaks.*.ended_at' => '休憩の終了',
            'hourly_wage' => '時給',
        ];
    }

    public function clockIn(): CarbonImmutable
    {
        return self::parse($this->string('clock_in_at')->toString());
    }

    public function clockOut(): ?CarbonImmutable
    {
        $v = $this->input('clock_out_at');

        return is_string($v) && $v !== '' ? self::parse($v) : null;
    }

    /**
     * 開始の順に並べた休憩
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>
     */
    public function breakTimes(): array
    {
        $rows = $this->input('breaks');
        $list = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! is_string($row['started_at'] ?? null)) {
                continue;
            }
            $end = $row['ended_at'] ?? null;
            $list[] = [self::parse($row['started_at']), is_string($end) && $end !== '' ? self::parse($end) : null];
        }
        usort($list, fn (array $a, array $b): int => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        return $list;
    }

    public function hasWage(): bool
    {
        return $this->exists('hourly_wage');
    }

    public function wage(): ?int
    {
        $v = $this->input('hourly_wage');

        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
    }

    private static function parse(string $value): CarbonImmutable
    {
        $t = CarbonImmutable::createFromFormat('!'.self::FORMAT, $value, BusinessDate::TIMEZONE);
        assert($t instanceof CarbonImmutable);

        return $t;
    }
}
