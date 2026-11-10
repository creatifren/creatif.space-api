<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit trail for connected apps and API keys: who (always the
 * caller), which token and client, which tool, when, and how it ended.
 * No content is ever recorded, so none is returned.
 */
class McpActivityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'token_kind' => ['sometimes', 'string', 'in:oauth,api_key'],
            'token_id' => ['sometimes', 'string', 'max:100'],
            'client_id' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = McpAuditLog::query()
            ->where('user_id', $user->id)
            ->when($validated['token_kind'] ?? null, fn ($q, $v) => $q->where('token_kind', $v))
            ->when($validated['token_id'] ?? null, fn ($q, $v) => $q->where('token_id', $v))
            ->when($validated['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'data' => collect($page->items())->map(fn (McpAuditLog $row) => [
                'token_kind' => $row->token_kind,
                // OAuth token ids are not shown: the client is what a person recognises.
                'token_id' => $row->token_kind === 'api_key' ? $row->token_id : null,
                'client_id' => $row->client_id,
                'client' => $row->client_name,
                'tool' => $row->tool,
                'route' => $row->route,
                'method' => $row->method,
                'status' => $row->status,
                'at' => $row->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
