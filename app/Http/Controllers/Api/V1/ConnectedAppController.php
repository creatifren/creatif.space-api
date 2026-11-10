<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mcp\OAuthRevoker;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

/**
 * Settings → Connected apps: every OAuth client (Claude, ChatGPT, …) that
 * holds a live grant for this user, and a way to cut it off. Disconnecting
 * revokes every access and refresh token the client holds for the user;
 * the client's registration itself stays (other people may use it).
 */
class ConnectedAppController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tokens = Token::query()
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->with('client')
            ->latest('created_at')
            ->get()
            ->groupBy('client_id');

        $apps = $tokens->map(function ($grants) {
            /** @var Token $latest */
            $latest = $grants->first();
            /** @var Client|null $client */
            $client = $latest->client;

            return [
                'id' => (string) $latest->client_id,
                'name' => $client === null ? 'Unknown app' : $client->name,
                'scopes' => $grants->pluck('scopes')->flatten()->unique()->values()->all(),
                'connected_at' => $grants->min('created_at')?->toIso8601String(),
                'last_authorized_at' => $latest->created_at?->toIso8601String(),
            ];
        })->values();

        return response()->json(['data' => $apps]);
    }

    public function destroy(Request $request, string $clientId, OAuthRevoker $revoker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $revoker->revokeClientForUser($clientId, $user);

        abort_if($revoked === 0, 404, 'That app is not connected to your account.');

        return response()->json(null, 204);
    }
}
