<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Settings → API keys: personal keys for MCP clients that cannot do OAuth
 * (Claude Desktop / Code config files, Cursor). Stored as a SHA-256 hash
 * (Sanctum personal access tokens); the plain key is in the create
 * response and nowhere else, ever. Session-only: a token cannot mint or
 * list tokens.
 */
class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $user->tokens()
                ->latest('id')
                ->get()
                ->map(fn (PersonalAccessToken $token) => $this->present($token))
                ->values(),
            'meta' => ['scopes' => config('mcp.scopes')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys((array) config('mcp.scopes')))],
            // Never-expiring is allowed: these sit in config files.
            'expires_in_days' => ['sometimes', 'nullable', 'integer', Rule::in([30, 90, 365])],
        ]);

        $active = $user->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
        if ($active >= (int) config('mcp.api_keys.max_active')) {
            throw ValidationException::withMessages([
                'name' => 'You have '.$active.' active API keys, the most allowed. Revoke one you no longer use first.',
            ]);
        }

        $expiresAt = isset($validated['expires_in_days']) ? now()->addDays($validated['expires_in_days']) : null;

        // `cs_live_` + 48 random characters. Only the SHA-256 is stored, in
        // Sanctum's personal_access_tokens table; the fixed prefix makes a
        // leaked key recognisable to secret scanners.
        $plain = config('mcp.api_keys.prefix').Str::random(48);

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->create([
            'name' => $validated['name'],
            'token' => hash('sha256', $plain),
            'abilities' => array_values(array_unique($validated['scopes'])),
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'data' => $this->present($token) + [
                // Shown once. Only its hash is stored.
                'key' => $plain,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $apiKey): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = $user->tokens()->whereKey($apiKey)->delete();

        abort_if($deleted === 0, 404, 'That API key does not exist or was already revoked.');

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PersonalAccessToken $token): array
    {
        return [
            'id' => (string) $token->getKey(),
            'name' => $token->name,
            'scopes' => array_values((array) $token->abilities),
            // Enough to tell keys apart; never enough to use one.
            'hint' => config('mcp.api_keys.prefix').'…'.Str::substr((string) $token->getKey(), -4),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
