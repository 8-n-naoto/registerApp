<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\ShiftRequestKind;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftMonth;
use App\Models\ShiftRequest;
use App\Models\User;
use App\Services\Labor\LaborSummary;
use App\Services\ShiftService;
use App\Support\BusinessDate;
use App\Support\CurrentStore;
use App\Support\ShiftTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** 13 §5 #81〜#87 勤務表（予定・締切・公開・希望） */
class ShiftController extends Controller
{
    public function __construct(
        private readonly CurrentStore $currentStore,
        private readonly ShiftService $shifts,
    ) {}

    /** #81 月の勤務表。owner は予定と全員の希望、staff は公開済みの予定（全員分。R7） */
    public function index(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $user = $this->user($request);
        $isOwner = $user->role === Role::Owner;
        $row = ShiftMonth::query()->where('month', $month)->first();
        $visible = $isOwner || $row?->published_at !== null;

        $shifts = $visible
            ? Shift::query()->where('date', 'like', $month.'-%')->orderBy('date')->orderBy('start_time')->orderBy('id')->get()
            : collect();
        $requests = $isOwner
            ? ShiftRequest::query()->where('date', 'like', $month.'-%')->orderBy('date')->orderBy('user_id')->get()
            : collect();
        $members = User::query()
            ->where('store_id', $this->currentStore->requireId())
            ->whereIn('role', [Role::Owner, Role::Staff])
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $shifts->pluck('user_id')->unique()->all()))
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['id', 'name', 'role', 'is_active']);

        return response()->json([
            'month' => $this->monthPayload($month, $row),
            'shifts' => $shifts->map(fn (Shift $s): array => self::shift($s))->values()->all(),
            'requests' => $requests->map(fn (ShiftRequest $r): array => self::request($r))->values()->all(),
            'members' => $members->map(fn (User $u): array => [
                'id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'is_active' => $u->is_active,
            ])->all(),
        ]);
    }

    /** #82 月の締切・メモ・公開 */
    public function updateMonth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'string', 'regex:'.LaborSummary::MONTH_PATTERN],
            'request_deadline' => ['present', 'nullable', 'string', 'date_format:Y-m-d'],
            'memo' => ['present', 'nullable', 'string', 'max:500'],
            'published' => ['required', 'boolean'],
        ], attributes: ['month' => '月', 'request_deadline' => '希望の締切', 'memo' => 'メモ', 'published' => '公開']);
        $month = (string) $validated['month'];

        $row = $this->shifts->updateMonth([
            'month' => $month,
            'request_deadline' => is_string($validated['request_deadline']) ? $validated['request_deadline'] : null,
            'memo' => is_string($validated['memo']) && $validated['memo'] !== '' ? $validated['memo'] : null,
            'published' => (bool) $validated['published'],
        ]);

        return response()->json($this->monthPayload($month, $row));
    }

    /** #83 予定を入れる */
    public function store(Request $request): JsonResponse
    {
        $shift = $this->shifts->create($this->shiftInput($request));

        return response()->json(self::shift($shift), 201);
    }

    /** #84 予定を変える */
    public function update(Request $request, Shift $shift): JsonResponse
    {
        return response()->json(self::shift($this->shifts->update($shift, $this->shiftInput($request))));
    }

    /** #85 予定を消す */
    public function destroy(Shift $shift): Response
    {
        $this->shifts->delete($shift);

        return response()->noContent();
    }

    /** #86 本人の希望 */
    public function myRequests(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $user = $this->user($request);
        $rows = ShiftRequest::query()
            ->where('user_id', $user->id)
            ->where('date', 'like', $month.'-%')
            ->orderBy('date')
            ->get();

        return response()->json([
            'month' => $this->monthPayload($month, ShiftMonth::query()->where('month', $month)->first()),
            'requests' => $rows->map(fn (ShiftRequest $r): array => self::request($r))->values()->all(),
        ]);
    }

    /** #87 本人の希望を出す（その月の分を置き換える。締切・公開の後は 422） */
    public function submitRequests(Request $request): JsonResponse
    {
        $month = $request->input('month');
        $prefix = is_string($month) ? $month.'-' : '';
        $validator = validator($request->all(), [
            'month' => ['required', 'string', 'regex:'.LaborSummary::MONTH_PATTERN],
            'requests' => ['present', 'array', 'max:31'],
            'requests.*' => ['array:date,kind,start_time,end_time,note'],
            'requests.*.date' => ['required', 'string', 'date_format:Y-m-d', 'starts_with:'.$prefix, 'distinct'],
            'requests.*.kind' => ['required', 'string', Rule::enum(ShiftRequestKind::class)],
            'requests.*.start_time' => ['present', 'nullable', 'string', 'regex:'.ShiftTime::PATTERN],
            'requests.*.end_time' => ['present', 'nullable', 'string', 'regex:'.ShiftTime::PATTERN],
            'requests.*.note' => ['present', 'nullable', 'string', 'max:100'],
        ], attributes: [
            'requests.*.date' => '日付', 'requests.*.kind' => '希望', 'requests.*.start_time' => '開始',
            'requests.*.end_time' => '終了', 'requests.*.note' => 'メモ',
        ]);
        $validator->after(function (Validator $v) use ($request): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            foreach ((array) $request->input('requests') as $i => $r) {
                $start = is_array($r) ? ($r['start_time'] ?? null) : null;
                $end = is_array($r) ? ($r['end_time'] ?? null) : null;
                if (($start === null) !== ($end === null)) {
                    $v->errors()->add("requests.$i.end_time", '時間帯は開始と終了の両方を入れてください');
                } elseif (is_string($start) && is_string($end) && ShiftTime::toMinutes($end) <= ShiftTime::toMinutes($start)) {
                    $v->errors()->add("requests.$i.end_time", '終了は開始より後にしてください');
                }
            }
        });
        $validator->validate();

        $requests = [];
        foreach ((array) $request->input('requests') as $r) {
            if (! is_array($r)) {
                continue;
            }
            $available = $r['kind'] === ShiftRequestKind::Available->value;
            $requests[] = [
                'date' => (string) $r['date'],
                'kind' => (string) $r['kind'],
                'start_time' => $available && is_string($r['start_time']) ? $r['start_time'] : null,
                'end_time' => $available && is_string($r['end_time']) ? $r['end_time'] : null,
                'note' => is_string($r['note']) && $r['note'] !== '' ? $r['note'] : null,
            ];
        }
        $this->shifts->submitRequests($this->currentStore->requireStore(), $this->user($request), (string) $month, $requests);

        return $this->myRequests($request);
    }

    /** @return array{user_id: int, date: string, start_time: string, end_time: string, break_minutes: int, note: string|null} */
    private function shiftInput(Request $request): array
    {
        $validator = validator($request->all(), [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')
                ->where('store_id', $this->currentStore->requireId())
                ->whereIn('role', [Role::Owner->value, Role::Staff->value])],
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'start_time' => ['required', 'string', 'regex:'.ShiftTime::PATTERN],
            'end_time' => ['required', 'string', 'regex:'.ShiftTime::PATTERN],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'note' => ['present', 'nullable', 'string', 'max:100'],
        ], attributes: [
            'user_id' => '従業員', 'date' => '日付', 'start_time' => '開始', 'end_time' => '終了',
            'break_minutes' => '休憩', 'note' => 'メモ',
        ]);
        $validator->after(function (Validator $v) use ($request): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $span = ShiftTime::toMinutes($request->string('end_time')->toString()) - ShiftTime::toMinutes($request->string('start_time')->toString());
            if ($span <= 0) {
                $v->errors()->add('end_time', '終了は開始より後にしてください');
            } elseif ($request->integer('break_minutes') >= $span) {
                $v->errors()->add('break_minutes', '休憩は勤務の時間より短くしてください');
            }
        });
        $validator->validate();
        $note = $request->input('note');

        return [
            'user_id' => $request->integer('user_id'),
            'date' => $request->string('date')->toString(),
            'start_time' => $request->string('start_time')->toString(),
            'end_time' => $request->string('end_time')->toString(),
            'break_minutes' => $request->integer('break_minutes'),
            'note' => is_string($note) && $note !== '' ? $note : null,
        ];
    }

    private function month(Request $request): string
    {
        $validated = $request->validate(['month' => ['nullable', 'string', 'regex:'.LaborSummary::MONTH_PATTERN]]);

        return is_string($validated['month'] ?? null)
            ? $validated['month']
            : substr(BusinessDate::current($this->currentStore->requireStore()), 0, 7);
    }

    /** @return array<string, mixed> */
    private function monthPayload(string $month, ?ShiftMonth $row): array
    {
        $today = BusinessDate::current($this->currentStore->requireStore());

        return [
            'month' => $month,
            'request_deadline' => $row?->request_deadline,
            'published_at' => $row?->published_at?->toIso8601String(),
            'memo' => $row?->memo,
            'accepting_requests' => $row === null || $row->acceptsRequests($today),
        ];
    }

    /** @return array<string, mixed> */
    private static function shift(Shift $s): array
    {
        return [
            'id' => $s->id,
            'user_id' => $s->user_id,
            'date' => $s->date,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'break_minutes' => $s->break_minutes,
            'note' => $s->note,
            'planned_minutes' => $s->plannedMinutes(),
        ];
    }

    /** @return array<string, mixed> */
    private static function request(ShiftRequest $r): array
    {
        return [
            'user_id' => $r->user_id,
            'date' => $r->date,
            'kind' => $r->kind->value,
            'start_time' => $r->start_time,
            'end_time' => $r->end_time,
            'note' => $r->note,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
