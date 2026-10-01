<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\ShiftRequestKind;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftMonth;
use App\Models\ShiftPattern;
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

/** 13 §5 #81〜#87 勤務表（予定・締切・公開・希望）。区分（13 §3.6）を選べる */
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
            'patterns' => $this->activePatterns(),
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
        return response()->json(self::shift($this->shifts->update($shift, $this->shiftInput($request, $shift))));
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
            'patterns' => $this->activePatterns(),
        ]);
    }

    /**
     * #87 本人の希望を出す（その月の分を置き換える。締切・公開の後は 422）。
     * 出られる日は区分かメモのどちらかが要る。区分は使っている区分か、その日にすでに出していた区分
     */
    public function submitRequests(Request $request): JsonResponse
    {
        $month = $request->input('month');
        $prefix = is_string($month) ? $month.'-' : '';
        $user = $this->user($request);
        $validator = validator($request->all(), [
            'month' => ['required', 'string', 'regex:'.LaborSummary::MONTH_PATTERN],
            'requests' => ['present', 'array', 'max:31'],
            'requests.*' => ['array:date,kind,pattern_id,note'],
            'requests.*.date' => ['required', 'string', 'date_format:Y-m-d', 'starts_with:'.$prefix, 'distinct'],
            'requests.*.kind' => ['required', 'string', Rule::enum(ShiftRequestKind::class)],
            'requests.*.pattern_id' => ['present', 'nullable', 'integer'],
            'requests.*.note' => ['present', 'nullable', 'string', 'max:100'],
        ], attributes: [
            'requests.*.date' => '日付', 'requests.*.kind' => '希望', 'requests.*.pattern_id' => '区分', 'requests.*.note' => 'メモ',
        ]);
        $requests = [];
        $validator->after(function (Validator $v) use ($request, $user, &$requests): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $patterns = ShiftPattern::query()->get()->keyBy('id');
            $previous = ShiftRequest::query()->where('user_id', $user->id)->pluck('shift_pattern_id', 'date');
            foreach ((array) $request->input('requests') as $i => $r) {
                if (! is_array($r)) {
                    continue;
                }
                $note = is_string($r['note']) && trim($r['note']) !== '' ? trim($r['note']) : null;
                $pattern = null;
                if ($r['kind'] === ShiftRequestKind::Available->value) {
                    $id = $r['pattern_id'];
                    if (is_int($id)) {
                        $pattern = $patterns->get($id);
                        if (! $pattern instanceof ShiftPattern || (! $pattern->is_active && $previous->get($r['date']) !== $id)) {
                            $v->errors()->add("requests.$i.pattern_id", '選んだ区分は使えません。選び直してください');

                            continue;
                        }
                    } elseif ($note === null) {
                        $v->errors()->add("requests.$i.pattern_id", '区分を選ぶか、メモを書いてください');

                        continue;
                    }
                }
                $times = $pattern?->times();
                $requests[] = [
                    'date' => (string) $r['date'],
                    'kind' => (string) $r['kind'],
                    'start_time' => $times['start_time'] ?? null,
                    'end_time' => $times['end_time'] ?? null,
                    'note' => $note,
                    'shift_pattern_id' => $pattern?->id,
                    'pattern_name' => $pattern?->name,
                    'segments' => $pattern?->segments,
                ];
            }
        });
        $validator->validate();

        $this->shifts->submitRequests($this->currentStore->requireStore(), $user, (string) $month, $requests);

        return $this->myRequests($request);
    }

    /**
     * 区分を選んだときは開始・終了・休憩を区分から決める（送られた時刻は使わない）。
     * 区分は使っている区分か、その予定がすでに選んでいた区分
     *
     * @return array{user_id: int, date: string, start_time: string, end_time: string, break_minutes: int, note: string|null, shift_pattern_id: int|null, pattern_name: string|null, segments: list<array{start: string, end: string}>|null}
     */
    private function shiftInput(Request $request, ?Shift $current = null): array
    {
        $patternId = $request->input('pattern_id');
        $byPattern = $patternId !== null;
        $pattern = is_int($patternId) ? ShiftPattern::query()->find($patternId) : null;
        $validator = validator($request->all(), [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')
                ->where('store_id', $this->currentStore->requireId())
                ->whereIn('role', [Role::Owner->value, Role::Staff->value])],
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'pattern_id' => ['nullable', 'integer'],
            ...($byPattern ? [] : [
                'start_time' => ['required', 'string', 'regex:'.ShiftTime::PATTERN],
                'end_time' => ['required', 'string', 'regex:'.ShiftTime::PATTERN],
                'break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            ]),
            'note' => ['present', 'nullable', 'string', 'max:100'],
        ], attributes: [
            'user_id' => '従業員', 'date' => '日付', 'pattern_id' => '区分', 'start_time' => '開始', 'end_time' => '終了',
            'break_minutes' => '休憩', 'note' => 'メモ',
        ]);
        $validator->after(function (Validator $v) use ($request, $byPattern, $pattern, $current): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if ($byPattern) {
                if ($pattern === null || (! $pattern->is_active && $current?->shift_pattern_id !== $pattern->id)) {
                    $v->errors()->add('pattern_id', '選んだ区分は使えません。選び直してください');
                }

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
        $times = $pattern?->times() ?? [
            'start_time' => $request->string('start_time')->toString(),
            'end_time' => $request->string('end_time')->toString(),
            'break_minutes' => $request->integer('break_minutes'),
        ];

        return [
            'user_id' => $request->integer('user_id'),
            'date' => $request->string('date')->toString(),
            ...$times,
            'note' => is_string($note) && $note !== '' ? $note : null,
            'shift_pattern_id' => $pattern?->id,
            'pattern_name' => $pattern?->name,
            'segments' => $pattern?->segments,
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
            'pattern_id' => $s->shift_pattern_id,
            'pattern_name' => $s->pattern_name,
            'segments' => $s->segments,
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
            'pattern_id' => $r->shift_pattern_id,
            'pattern_name' => $r->pattern_name,
            'segments' => $r->segments,
        ];
    }

    /** @return array<int, array<string, mixed>> 使っている区分（希望・予定で選べるもの） */
    private function activePatterns(): array
    {
        return ShiftPattern::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()
            ->map(fn (ShiftPattern $p): array => ShiftPatternController::pattern($p))->values()->all();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
