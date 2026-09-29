<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethodRequest;
use App\Http\Requests\ReorderRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Services\PaymentMethodService;
use Illuminate\Http\Response;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentMethodService $methods) {}

    /** #35 POST /payment-methods（06 §8.4） */
    public function store(PaymentMethodRequest $request): PaymentMethodResource
    {
        return PaymentMethodResource::make($this->methods->create([
            'name' => $request->string('name')->toString(),
            'is_cash' => $request->boolean('is_cash'),
        ]));
    }

    /** #36 PUT /payment-methods/{id} */
    public function update(PaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        return PaymentMethodResource::make($this->methods->update($paymentMethod, [
            'name' => $request->string('name')->toString(),
            'is_cash' => $request->boolean('is_cash'),
            'is_active' => $request->boolean('is_active'),
        ]));
    }

    /** #37 PUT /payment-methods/order */
    public function reorder(ReorderRequest $request): Response
    {
        $this->methods->reorder($request->ids());

        return response()->noContent();
    }
}
