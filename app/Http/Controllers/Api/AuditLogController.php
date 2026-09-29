<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    public const PER_PAGE = 50;

    /**
     * #42 GET /logs?page=&action=（06 §10.1、§1.7）。店舗の範囲は BelongsToStore のスコープで決まる：
     * owner は自店舗、admin は ?store_id があればその店舗（無い店舗は 404）、無ければ全店舗（店舗の無い記録も含む）
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', Rule::enum(AuditAction::class)],
        ]);
        $action = is_string($validated['action'] ?? null) ? $validated['action'] : null;

        $page = AuditLog::query()
            ->with(['user:id,name', 'store:id,name'])
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'store_name' => $log->store->name ?? null,
                'user_name' => $log->user->name ?? null,
                'action' => $log->action,
                'action_label' => AuditAction::tryFrom($log->action)?->label() ?? $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
