<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\MeResource;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** #1 POST /login（06 §3.1） */
    public function login(LoginRequest $request): MeResource
    {
        $user = $this->auth->login(
            $request,
            $request->string('login_id')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        return MeResource::make($user);
    }

    /** #2 POST /logout（06 §3.2） */
    public function logout(Request $request): Response
    {
        $this->auth->logout($request);

        return response()->noContent();
    }
}
