<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderTableRequest;
use App\Http\Resources\OrderTableResource;
use App\Models\OrderTable;
use App\Services\OrderTableService;
use App\Support\TableQrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** 12 §5.11・§5.12 テーブル（#56〜#63）。{orderTable} は BelongsToStore のスコープで解決する（他店舗は 404） */
class OrderTableController extends Controller
{
    public function __construct(private readonly OrderTableService $tables) {}

    /** #56 GET /order-tables（owner / staff） */
    public function index(): JsonResponse
    {
        $tables = OrderTable::query()->withUnpaid()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['tables' => OrderTableResource::collection($tables)]);
    }

    /** #57 POST /order-tables（owner） */
    public function store(OrderTableRequest $request): JsonResponse
    {
        $table = $this->tables->create(
            $request->string('name')->toString(),
            $request->has('sort_order') ? $request->integer('sort_order') : null,
        );

        return OrderTableResource::make($table)->response()->setStatusCode(201);
    }

    /** #58 PUT /order-tables/{id}（owner） */
    public function update(OrderTableRequest $request, OrderTable $orderTable): OrderTableResource
    {
        return OrderTableResource::make($this->tables->update($orderTable, [
            'name' => $request->string('name')->toString(),
            'sort_order' => $request->integer('sort_order'),
            'is_active' => $request->boolean('is_active'),
        ]));
    }

    /** #59 DELETE /order-tables/{id}（owner） */
    public function destroy(OrderTable $orderTable): Response
    {
        $this->tables->delete($orderTable);

        return response()->noContent();
    }

    /** #60 POST /order-tables/{id}/token（owner） */
    public function regenerateToken(OrderTable $orderTable): OrderTableResource
    {
        return OrderTableResource::make($this->tables->regenerateToken($orderTable));
    }

    /** #61 GET /order-tables/{id}/qr（owner）。URL にトークンを含むため保存させない。操作ログは記録しない（12 §5.19） */
    public function qr(OrderTable $orderTable): JsonResponse
    {
        $url = TableQrCode::url($orderTable);

        return response()
            ->json(['url' => $url, 'svg' => TableQrCode::svg($url)])
            ->header('Cache-Control', 'no-store');
    }

    /** #62 POST /order-tables/{id}/open（owner / staff） */
    public function open(OrderTable $orderTable): OrderTableResource
    {
        return OrderTableResource::make($this->tables->open($orderTable));
    }

    /** #63 POST /order-tables/{id}/close（owner / staff） */
    public function close(OrderTable $orderTable): OrderTableResource
    {
        return OrderTableResource::make($this->tables->close($orderTable));
    }
}
