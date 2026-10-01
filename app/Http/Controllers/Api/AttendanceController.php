<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\MeResource;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\AuthService;
use App\Services\Labor\LaborSummary;
use App\Support\BusinessDate;
use App\Support\Csv;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** 13 §5 #68〜#76 打刻・勤怠・集計 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly CurrentStore $currentStore,
    ) {}

    /** #68 ログインしたままの端末で出勤する（13 §6.1-2）。本人のパスワードを確かめる */
    public function clockIn(Request $request): MeResource
    {
        $request->validate(['password' => ['required', 'string', 'max:255']], attributes: ['password' => 'パスワード']);
        $user = $this->user($request);

        $key = 'clock-in:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, AuthService::MAX_ATTEMPTS)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($key, AuthService::DECAY_SECONDS);

            throw ValidationException::withMessages(['password' => ['パスワードが違います']]);
        }
        RateLimiter::clear($key);
        $this->attendance->clockInAgain($user);

        return MeResource::make($user);
    }

    /** #69 休憩開始 */
    public function breakStart(Request $request): MeResource
    {
        $user = $this->user($request);
        $this->attendance->startBreak($user);

        return MeResource::make($user);
    }

    /** #70 休憩終了 */
    public function breakEnd(Request $request): MeResource
    {
        $user = $this->user($request);
        $this->attendance->endBreak($user);

        return MeResource::make($user);
    }

    /** #71 月の打刻の一覧。owner は全員（user_id で絞れる）、staff は本人のみ */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'string', 'regex:'.LaborSummary::MONTH_PATTERN],
            'user_id' => ['nullable', 'integer'],
        ]);
        $store = $this->currentStore->requireStore();
        $user = $this->user($request);
        $month = is_string($validated['month'] ?? null) ? $validated['month'] : substr(BusinessDate::current($store), 0, 7);
        $userId = $user->role === Role::Owner
            ? (isset($validated['user_id']) ? (int) $validated['user_id'] : null)
            : $user->id;
        [$from, $to] = LaborSummary::monthRange($month);

        $rows = Attendance::query()
            ->whereBetween('business_date', [$from, $to])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->with(['user:id,name', 'breaks'])
            ->orderBy('clock_in_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'month' => $month,
            'attendances' => AttendanceResource::collection($rows),
        ]);
    }

    /** #72 owner が打刻を追加する */
    public function store(AttendanceRequest $request): JsonResponse
    {
        $data = [
            'user_id' => $request->integer('user_id'),
            'clock_in_at' => $request->clockIn(),
            'clock_out_at' => $request->clockOut(),
            'breaks' => $request->breakTimes(),
        ];
        if ($request->hasWage()) {
            $data['hourly_wage'] = $request->wage();
        }
        $row = $this->attendance->create($this->currentStore->requireStore(), $this->user($request), $data);

        return AttendanceResource::make($row->load(['user:id,name', 'breaks']))->response()->setStatusCode(201);
    }

    /** #73 owner が打刻を修正する */
    public function update(AttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        $row = $this->attendance->update($this->currentStore->requireStore(), $this->user($request), $attendance, [
            'clock_in_at' => $request->clockIn(),
            'clock_out_at' => $request->clockOut(),
            'breaks' => $request->breakTimes(),
            'hourly_wage' => $request->hasWage() ? $request->wage() : $attendance->hourly_wage,
        ]);

        return AttendanceResource::make($row->load(['user:id,name', 'breaks']));
    }

    /** #74 owner が打刻を削除する */
    public function destroy(Attendance $attendance): Response
    {
        $this->attendance->delete($attendance);

        return response()->noContent();
    }

    /** #75 月の集計（勤務時間・人件費・警告） */
    public function summary(Request $request, LaborSummary $summary): JsonResponse
    {
        $store = $this->currentStore->requireStore();

        return response()->json($summary->summarize($store, $this->month($request, $store)));
    }

    /** #76 月の集計の CSV */
    public function export(Request $request, LaborSummary $summary): StreamedResponse
    {
        $store = $this->currentStore->requireStore();
        $month = $this->month($request, $store);
        $data = $summary->summarize($store, $month);
        $roles = ['owner' => 'オーナー', 'staff' => 'スタッフ'];
        $labels = [
            'wage_missing' => '時給未設定',
            'below_minimum_wage' => '最低賃金未満',
            'overtime_45h' => '時間外45時間超',
            'break_shortage' => '休憩不足',
            'open_attendance' => '退勤未打刻',
        ];
        $hm = fn (int $m): string => intdiv($m, 60).':'.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);

        return response()->stream(function () use ($data, $roles, $labels, $hm): void {
            echo Csv::BOM;
            echo Csv::line(['氏名', '役割', '出勤日数', '勤務時間', '時間外', 'うち月60時間超', '深夜', '法定休日', '予定', '時給', '基本給', '割増', '人件費', '注意']);
            foreach ($data['rows'] as $row) {
                /** @var list<string> $warnings */
                $warnings = $row['warnings'];
                echo Csv::line([
                    (string) $row['name'],
                    $roles[(string) $row['role']] ?? '',
                    (int) $row['days'],
                    $hm((int) $row['work_minutes']),
                    $hm((int) $row['overtime_minutes']),
                    $hm((int) $row['overtime_over60_minutes']),
                    $hm((int) $row['night_minutes']),
                    $hm((int) $row['holiday_minutes']),
                    $hm((int) $row['scheduled_minutes']),
                    is_int($row['hourly_wage']) ? $row['hourly_wage'] : null,
                    is_int($row['base_pay']) ? $row['base_pay'] : null,
                    is_int($row['premium_pay']) ? $row['premium_pay'] : null,
                    is_int($row['total_pay']) ? $row['total_pay'] : null,
                    implode(' ', array_map(fn (string $w): string => $labels[$w] ?? $w, $warnings)),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="attendance_'.$month.'.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function month(Request $request, Store $store): string
    {
        $validated = $request->validate(['month' => ['nullable', 'string', 'regex:'.LaborSummary::MONTH_PATTERN]]);

        return is_string($validated['month'] ?? null) ? $validated['month'] : substr(BusinessDate::current($store), 0, 7);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
