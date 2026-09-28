<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MeController extends Controller
{
    /** #3 GET /me（06 §3.3） */
    public function show(Request $request): MeResource
    {
        return MeResource::make($this->user($request));
    }

    /** #4 PUT /me/password（06 §3.4） */
    public function updatePassword(UpdatePasswordRequest $request, AuditLogger $audit): Response
    {
        $user = $this->user($request);

        DB::transaction(function () use ($request, $user, $audit): void {
            $user->forceFill([
                'password' => $request->string('password')->toString(),
                'remember_token' => Str::random(60),
            ])->save();
            $audit->log(AuditAction::PasswordChanged, $user, storeId: $user->store_id);
        });

        // Sanctum の AuthenticateSession がセッションに控えたハッシュと比べるため、この端末の分を新しい値にする
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
