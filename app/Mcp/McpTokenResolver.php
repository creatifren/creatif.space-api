<?php

namespace App\Mcp;

use App\Enums\UserStatus;
use App\Models\User;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Laravel\Sanctum\PersonalAccessToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use LogicException;

/**
 * Turns a bearer token into a TokenContext, or null.
 *
 * Two shapes are accepted:
 *  - an OAuth 2.1 access token issued by Passport (a signed JWT), checked
 *    against the signing key, its expiry and the revoked flag;
 *  - a personal API key (`cs_live_…`), kept as a SHA-256 hash in
 *    Sanctum's personal_access_tokens table, checked for expiry.
 *
 * Suspended or deleted accounts never resolve. Nothing here trusts a
 * header other than the token itself.
 */
class McpTokenResolver
{
    /**
     * The resource server is resolved only for a JWT: building it reads
     * Passport's key, and requests without a bearer (or with an API key)
     * must never depend on that key being present.
     */
    private function server(): ResourceServer
    {
        return app(ResourceServer::class);
    }

    public function fromRequest(Request $request): ?TokenContext
    {
        $token = $request->bearerToken();

        return $token === null || $token === '' ? null : $this->resolve($token);
    }

    public function resolve(string $token): ?TokenContext
    {
        if (str_starts_with($token, (string) config('mcp.api_keys.prefix'))) {
            return $this->apiKey($token);
        }

        if (substr_count($token, '.') === 2) {
            return $this->accessToken($token);
        }

        return null;
    }

    private function apiKey(string $plain): ?TokenContext
    {
        $model = PersonalAccessToken::query()->where('token', hash('sha256', $plain))->first();

        if ($model === null || ($model->expires_at !== null && $model->expires_at->isPast())) {
            return null;
        }

        $user = $model->tokenable;
        if (! $user instanceof User || ! $this->usable($user)) {
            return null;
        }

        // Throttled write: last_used_at does not need to be exact.
        if ($model->last_used_at === null || $model->last_used_at->lt(now()->subMinute())) {
            $model->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $scopes = array_values(array_intersect(
            (array) $model->abilities,
            array_keys((array) config('mcp.scopes')),
        ));

        return new TokenContext(
            user: $user,
            kind: TokenContext::KIND_API_KEY,
            tokenId: (string) $model->getKey(),
            scopes: $scopes,
            clientId: null,
            clientName: $model->name,
            expiresAt: $model->expires_at?->getTimestamp(),
            issuedAt: $model->created_at?->getTimestamp(),
        );
    }

    private function accessToken(string $jwt): ?TokenContext
    {
        $psr = new ServerRequest('GET', '/', ['Authorization' => 'Bearer '.$jwt]);

        try {
            // Signature, expiry, not-before and the revoked flag.
            $psr = $this->server()->validateAuthenticatedRequest($psr);
        } catch (OAuthServerException) {
            return null;
        } catch (LogicException $e) {
            // Passport's key missing or unreadable: a deploy problem, not the
            // caller's. Logged, not report()ed — error tracking asks the
            // guard for the user, which would land back here.
            Log::error('mcp.passport_key_unusable', ['error' => $e->getMessage()]);

            return null;
        }

        $tokenId = (string) $psr->getAttribute('oauth_access_token_id');
        $token = Token::query()->find($tokenId);
        if ($token === null || $token->revoked) {
            return null;
        }

        $user = User::query()->find($psr->getAttribute('oauth_user_id'));
        if ($user === null || ! $this->usable($user)) {
            return null;
        }

        /** @var Client|null $client */
        $client = Client::query()->find($psr->getAttribute('oauth_client_id'));
        if ($client === null || $client->revoked) {
            return null;
        }

        $scopes = array_values(array_intersect(
            (array) $psr->getAttribute('oauth_scopes', []),
            array_keys((array) config('mcp.scopes')),
        ));

        return new TokenContext(
            user: $user,
            kind: TokenContext::KIND_OAUTH,
            tokenId: $tokenId,
            scopes: $scopes,
            clientId: (string) $client->getKey(),
            clientName: $client->name,
            expiresAt: $token->expires_at?->getTimestamp(),
            issuedAt: $token->created_at?->getTimestamp(),
        );
    }

    private function usable(User $user): bool
    {
        return $user->status === UserStatus::Active && ! $user->trashed();
    }
}
