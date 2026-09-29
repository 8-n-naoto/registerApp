<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffPasswordRequest;
use App\Http\Requests\StaffRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\StaffService;
use App\Support\CurrentStore;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** 06 §9 スタッフ管理（owner のみ）。{staff} は自店舗の staff に限って解決する（AppServiceProvider） */
class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly CurrentStore $currentStore,
    ) {}

    /** #38 GET /staff */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()
            ->where('store_id', $this->currentStore->requireId())
            ->where('role', Role::Staff)
            ->orderBy('id')
            ->get());
    }

    /** #39 POST /staff */
    public function store(StaffRequest $request): UserResource
    {
        return UserResource::make($this->staff->create([
            'login_id' => $request->string('login_id')->toString(),
            'name' => $request->string('name')->toString(),
            'password' => $request->string('password')->toString(),
        ]));
    }

    /** #40 PUT /staff/{id} */
    public function update(StaffRequest $request, User $staff): UserResource
    {
        return UserResource::make($this->staff->update($staff, [
            'name' => $request->string('name')->toString(),
            'is_active' => $request->boolean('is_active'),
        ]));
    }

    /** #41 PUT /staff/{id}/password */
    public function updatePassword(StaffPasswordRequest $request, User $staff): Response
    {
        $this->staff->resetPassword($staff, $request->string('password')->toString());

        return response()->noContent();
    }
}
