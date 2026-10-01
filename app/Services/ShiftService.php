<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Shift;
use App\Models\ShiftMonth;
use App\Models\ShiftRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\ShiftTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 13 §6.5 勤務表：月の締切・公開（#82）、予定（#83〜#85）、希望の提出（#87）
 */
final class ShiftService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** 月の行。無ければ作る（予定を入れたときにも作る。13 §6.5-1） */
    public static function month(string $month): ShiftMonth
    {
        return ShiftMonth::query()->firstOrCreate(['month' => $month]);
    }

    /** @param  array{month: string, request_deadline: string|null, memo: string|null, published: bool}  $data */
    public function updateMonth(array $data): ShiftMonth
    {
        return DB::transaction(function () use ($data): ShiftMonth {
            $row = self::month($data['month']);
            $before = $row->attributesToArray();
            $published = $data['published'];
            $row->fill([
                'request_deadline' => $data['request_deadline'],
                'memo' => $data['memo'],
                // 公開済みのまま保存しても公開の日時は変えない
                'published_at' => $published ? ($row->published_at ?? now()) : null,
            ])->save();
            [$b, $a] = AuditLogger::diffModel($before, $row);
            if ($a !== []) {
                $this->audit->log(AuditAction::ShiftMonthUpdated, $row, ['month' => $row->month, ...$b], ['month' => $row->month, ...$a]);
            }

            return $row;
        });
    }

    /** @param  array{user_id: int, date: string, start_time: string, end_time: string, break_minutes: int, note: string|null}  $data */
    public function create(array $data): Shift
    {
        return DB::transaction(function () use ($data): Shift {
            $this->assertNoOverlap($data, null);
            self::month(substr($data['date'], 0, 7));
            $shift = Shift::query()->create($data);
            $this->audit->log(AuditAction::ShiftCreated, $shift, after: self::snapshot($shift));

            return $shift;
        });
    }

    /** @param  array{user_id: int, date: string, start_time: string, end_time: string, break_minutes: int, note: string|null}  $data */
    public function update(Shift $shift, array $data): Shift
    {
        return DB::transaction(function () use ($shift, $data): Shift {
            $this->assertNoOverlap($data, $shift->id);
            self::month(substr($data['date'], 0, 7));
            $before = $shift->attributesToArray();
            $shift->fill($data)->save();
            [$b, $a] = AuditLogger::diffModel($before, $shift);
            if ($a !== []) {
                $this->audit->log(AuditAction::ShiftUpdated, $shift, $b, $a);
            }

            return $shift;
        });
    }

    public function delete(Shift $shift): void
    {
        DB::transaction(function () use ($shift): void {
            $shift->delete();
            $this->audit->log(AuditAction::ShiftDeleted, $shift, before: self::snapshot($shift));
        });
    }

    /**
     * #87 本人の希望をその月の分だけ置き換える。公開後・締切後は 422
     *
     * @param  list<array{date: string, kind: string, start_time: string|null, end_time: string|null, note: string|null}>  $requests
     */
    public function submitRequests(Store $store, User $user, string $month, array $requests): void
    {
        DB::transaction(function () use ($store, $user, $month, $requests): void {
            $row = self::month($month);
            if (! $row->acceptsRequests(BusinessDate::current($store))) {
                throw new BusinessException(ErrorCode::ShiftRequestClosed, 'この月の希望の受付は終わりました', 422);
            }
            ShiftRequest::query()
                ->where('user_id', $user->id)
                ->where('date', 'like', $month.'-%')
                ->delete();
            foreach ($requests as $r) {
                $req = new ShiftRequest([
                    'date' => $r['date'],
                    'kind' => $r['kind'],
                    'start_time' => $r['start_time'],
                    'end_time' => $r['end_time'],
                    'note' => $r['note'],
                ]);
                $req->forceFill(['user_id' => $user->id])->save();
            }
            $this->audit->log(AuditAction::ShiftRequestsSubmitted, $row, after: [
                'month' => $month,
                'requests' => array_map(
                    fn (array $r): string => $r['date'].' '.$r['kind'].($r['start_time'] !== null ? ' '.$r['start_time'].'〜'.($r['end_time'] ?? '') : ''),
                    $requests,
                ),
            ]);
        });
    }

    /**
     * 同じ人の前日・当日・翌日の予定と時間が重なれば 422（24 時以降の時刻は翌朝として比べる）
     *
     * @param  array{user_id: int, date: string, start_time: string, end_time: string}  $data
     */
    private function assertNoOverlap(array $data, ?int $exceptId): void
    {
        $date = CarbonImmutable::parse($data['date']);
        $abs = fn (string $d, string $t): int => (int) $date->diffInDays(CarbonImmutable::parse($d), false) * 1440 + ShiftTime::toMinutes($t);
        $start = $abs($data['date'], $data['start_time']);
        $end = $abs($data['date'], $data['end_time']);

        $others = Shift::query()
            ->where('user_id', $data['user_id'])
            ->whereBetween('date', [$date->subDay()->format('Y-m-d'), $date->addDay()->format('Y-m-d')])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->get();
        foreach ($others as $o) {
            if ($abs($o->date, $o->start_time) < $end && $start < $abs($o->date, $o->end_time)) {
                throw new BusinessException(ErrorCode::ShiftOverlap, 'この人のほかの予定と時間が重なっています', 422, errors: [
                    'start_time' => ['この人のほかの予定と時間が重なっています'],
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function snapshot(Shift $s): array
    {
        return [
            'user_id' => $s->user_id,
            'date' => $s->date,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'break_minutes' => $s->break_minutes,
            'note' => $s->note,
        ];
    }
}
