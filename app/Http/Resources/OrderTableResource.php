<?php

namespace App\Http\Resources;

use App\Models\OrderTable;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 12 §4 OrderTable。unpaid_* は呼び出し側で OrderTable::withUnpaid() を付けておく。
 * トークン（平文・ハッシュ・暗号文）は返さない（QR は GET /order-tables/{id}/qr だけ）
 *
 * @mixin OrderTable
 */
class OrderTableResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $store = app(CurrentStore::class)->requireStore();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'session_expires_at' => $this->sessionExpiresAt($store)?->toIso8601String(),
            'unpaid_order_count' => (int) ($this->unpaid_order_count ?? 0),
            'unpaid_subtotal' => (int) ($this->unpaid_subtotal ?? 0),
            'token_rotated_at' => $this->token_rotated_at->toIso8601String(),
        ];
    }
}
