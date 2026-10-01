<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Services\OperatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 13 §5 #66・#67 端末の担当者（ログインしていなくても、端末の店舗があれば使える） */
class OperatorController extends Controller
{
    public function __construct(private readonly OperatorService $operators) {}

    /** #66 端末の店舗で勤務中の人 */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'operators' => $this->operators->operators(OperatorService::deviceStoreId($request)),
        ]);
    }

    /** #67 担当者を切り替える（owner へはパスワードが必要） */
    public function switch(Request $request): MeResource
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'password' => ['nullable', 'string', 'max:255'],
        ], attributes: ['user_id' => '担当者', 'password' => 'パスワード']);

        $user = $this->operators->switchTo(
            $request,
            (int) $validated['user_id'],
            is_string($validated['password'] ?? null) ? $validated['password'] : null,
        );

        return MeResource::make($user);
    }
}
