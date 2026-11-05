<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminStoreRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalesReport;
use App\Services\StoreRegistrar;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** 06 §11.1・§11.2・§11.4（admin のみ） */
class StoreController extends Controller
{
    public function __construct(
        private readonly SalesReport $report,
        private readonly AuditLogger $audit,
        private readonly StoreRegistrar $registrar,
    ) {}

    /** #43 GET /admin/stores。today は店舗ごとの締め時刻で計算した現在の営業日 */
    public function index(): JsonResponse
    {
        $stores = Store::query()
            ->with(['users' => fn ($q) => $q->where('role', Role::Owner)->orderBy('id')->select(['id', 'store_id', 'login_id'])])
            ->withCount([
                'users as staff_count' => fn (Builder $q) => $q->where('role', Role::Staff),
                'products as product_count' => fn (Builder $q) => $q->withoutGlobalScope('store'),
            ])
            ->orderBy('id')
            ->get();

        $today = $this->report->totalsByStore(
            $stores->mapWithKeys(fn (Store $s): array => [$s->id => BusinessDate::current($s)])->all(),
        );

        return response()->json([
            'stores' => $stores->map(fn (Store $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'is_active' => $s->is_active,
                'owner_login_ids' => $s->users->map(fn (User $u): string => $u->login_id)->values()->all(),
                'staff_count' => (int) $s->getAttribute('staff_count'),
                'product_count' => (int) $s->getAttribute('product_count'),
                'today' => $today[$s->id],
            ])->all(),
        ]);
    }

    /** #107 POST /admin/stores。店舗とオーナーを同時に作る。税区分・支払方法はオーナーの初回ログインで作られる（D-006） */
    public function store(AdminStoreRequest $request): JsonResponse
    {
        [$store, $owner] = DB::transaction(function () use ($request): array {
            [$store, $owner] = $this->registrar->register(
                $request->string('name')->toString(),
                $request->string('owner_login_id')->toString(),
                $request->string('owner_name')->toString(),
                $request->string('password')->toString(),
            );
            $this->audit->log(AuditAction::StoreCreated, $store, after: [
                'name' => $store->name,
                'owner_login_id' => $owner->login_id,
                'owner_name' => $owner->name,
            ], storeId: $store->id);

            return [$store, $owner];
        });

        return response()->json(['id' => $store->id, 'name' => $store->name, 'owner_login_id' => $owner->login_id], 201);
    }

    /** #44 PATCH /admin/stores/{id}/active。停止した店舗の owner / staff は次の API 呼び出しで 403 STORE_SUSPENDED（06 §1.5） */
    public function updateActive(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN);

        DB::transaction(function () use ($store, $active): void {
            if ($store->is_active === $active) {
                return; // 変化が無ければ記録しない
            }
            $store->is_active = $active;
            $store->save();
            $this->audit->log(
                $active ? AuditAction::StoreResumed : AuditAction::StoreSuspended,
                $store,
                ['is_active' => ! $active],
                ['is_active' => $active],
                storeId: $store->id,
            );
        });

        return response()->json(['id' => $store->id, 'is_active' => $store->is_active]);
    }
}
