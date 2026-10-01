<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Labor\LaborSummary;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** 13 §5 #77〜#80 労働条件（店舗）と、時給・区分（人） */
class LaborController extends Controller
{
    private const SETTINGS = ['weekly_hours_limit', 'week_start_day', 'legal_holiday_day', 'minimum_wage'];

    public function __construct(
        private readonly CurrentStore $currentStore,
        private readonly AuditLogger $audit,
    ) {}

    /** #77 */
    public function showSettings(): JsonResponse
    {
        return response()->json($this->settings($this->store()));
    }

    /** #78 未入力（null）にも戻せる。未入力の項目は owner に警告する（13 §6.6） */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weekly_hours_limit' => ['present', 'nullable', 'integer', Rule::in([40, 44])],
            'week_start_day' => ['present', 'nullable', 'integer', 'between:0,6'],
            'legal_holiday_day' => ['present', 'nullable', 'integer', 'between:0,6'],
            'minimum_wage' => ['present', 'nullable', 'integer', 'between:1,10000'],
        ], attributes: [
            'weekly_hours_limit' => '週の労働時間の上限',
            'week_start_day' => '週の起算日',
            'legal_holiday_day' => '法定休日',
            'minimum_wage' => '最低賃金',
        ]);
        $store = $this->store();

        DB::transaction(function () use ($store, $validated): void {
            $before = $store->attributesToArray();
            $store->forceFill(array_map(
                fn ($v): ?int => $v === null ? null : (int) $v,
                array_intersect_key($validated, array_flip(self::SETTINGS)),
            ))->save();
            [$b, $a] = AuditLogger::diffModel($before, $store);
            if ($a !== []) {
                $this->audit->log(AuditAction::LaborSettingsUpdated, $store, $b, $a, $store->id);
            }
        });

        return response()->json($this->settings($store));
    }

    /** #79 時給・区分の一覧（停止中の人も含む） */
    public function members(): JsonResponse
    {
        $members = User::query()
            ->where('store_id', $this->currentStore->requireId())
            ->whereIn('role', [Role::Owner, Role::Staff])
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->map(fn (User $u): array => self::member($u))
            ->all();

        return response()->json(['members' => $members]);
    }

    /** #80 時給・区分（管理監督者等）を変える。打刻済みの行の時給の写しは変えない */
    public function updateMember(Request $request, User $member): JsonResponse
    {
        $validated = $request->validate([
            'hourly_wage' => ['present', 'nullable', 'integer', 'between:0,100000'],
            'overtime_exempt' => ['required', 'boolean'],
        ], attributes: ['hourly_wage' => '時給', 'overtime_exempt' => '管理監督者等']);

        DB::transaction(function () use ($member, $validated): void {
            $before = $member->attributesToArray();
            $member->forceFill([
                'hourly_wage' => $validated['hourly_wage'] === null ? null : (int) $validated['hourly_wage'],
                'overtime_exempt' => (bool) $validated['overtime_exempt'],
            ])->save();
            [$b, $a] = AuditLogger::diffModel($before, $member);
            if ($a !== []) {
                $this->audit->log(AuditAction::LaborMemberUpdated, $member, $b, $a, $member->store_id);
            }
        });

        return response()->json(self::member($member));
    }

    /** @return array<string, mixed> */
    private function settings(Store $store): array
    {
        return [
            'weekly_hours_limit' => $store->weekly_hours_limit,
            'week_start_day' => $store->week_start_day,
            'legal_holiday_day' => $store->legal_holiday_day,
            'minimum_wage' => $store->minimum_wage,
            'warnings' => LaborSummary::settingsWarnings($store),
        ];
    }

    /** @return array<string, mixed> */
    private static function member(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->role->value,
            'is_active' => $u->is_active,
            'hourly_wage' => $u->hourly_wage,
            'overtime_exempt' => $u->overtime_exempt,
        ];
    }

    private function store(): Store
    {
        return Store::query()->findOrFail($this->currentStore->requireId());
    }
}
