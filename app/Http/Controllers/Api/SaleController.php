<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    /** #6 POST /sales（06 §4.2）。新規は 201、冪等の再送は 200 */
    public function store(SaleRequest $request): JsonResponse
    {
        $store = RegisterController::store($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        [$sale, $created] = $this->sales->confirm($store, $user, $request->saleInput());

        return SaleResource::make($sale)->response()->setStatusCode($created ? 201 : 200);
    }
}
