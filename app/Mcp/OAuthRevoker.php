<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use League\OAuth2\Server\CryptTrait;
use Throwable;

/**
 * Revokes OAuth grants: one token (RFC 7009, from the client) or every
 * token a client holds for a user (Settings → Connected apps).
 *
 * An access token is a JWT whose `jti` is the oauth_access_tokens id; a
 * refresh token is the authorization server's encrypted payload naming
 * its own id and its access token's. Either way both halves go.
 */
class OAuthRevoker
{
    use CryptTrait;

    public function __construct()
    {
        $this->setEncryptionKey(Passport::tokenEncryptionKey(app('encrypter')));
    }

    /**
     * Revoke the token if it belongs to the client. Unknown, malformed or
     * someone else's tokens are ignored — RFC 7009 answers 200 regardless.
     */
    public function revokeToken(string $token, string $clientId, ?string $hint = null): void
    {
        $order = $hint === 'refresh_token' ? ['refresh', 'access'] : ['access', 'refresh'];

        foreach ($order as $kind) {
            $accessTokenId = $kind === 'access' ? $this->accessTokenIdFromJwt($token) : $this->accessTokenIdFromRefresh($token);
            if ($accessTokenId === null) {
                continue;
            }

            $access = Token::query()->find($accessTokenId);
            if ($access !== null && (string) $access->client_id === $clientId) {
                $this->revokeAccessTokens([$access->id]);
            }

            return;
        }
    }

    /** @return int how many access tokens were live and are now revoked */
    public function revokeClientForUser(string $clientId, User $user): int
    {
        $ids = Token::query()
            ->where('client_id', $clientId)
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->pluck('id')
            ->all();

        $this->revokeAccessTokens($ids);

        // Codes not yet exchanged would mint a fresh token after the cut.
        Passport::authCode()->newQuery()
            ->where('client_id', $clientId)
            ->where('user_id', $user->getKey())
            ->update(['revoked' => true]);

        return count($ids);
    }

    /** @param  list<string>  $ids */
    private function revokeAccessTokens(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            Token::query()->whereIn('id', $ids)->update(['revoked' => true]);
            RefreshToken::query()->whereIn('access_token_id', $ids)->update(['revoked' => true]);
        });
    }

    private function accessTokenIdFromJwt(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $jti = is_array($claims) ? ($claims['jti'] ?? null) : null;

        // Only a lookup key: the row must exist and belong to the caller.
        return is_string($jti) && preg_match('/^[a-f0-9]{80}$/', $jti) === 1 ? $jti : null;
    }

    private function accessTokenIdFromRefresh(string $token): ?string
    {
        try {
            $payload = json_decode($this->decrypt($token), true);
        } catch (Throwable) {
            return null;
        }

        $id = is_array($payload) ? ($payload['access_token_id'] ?? null) : null;

        return is_string($id) ? $id : null;
    }
}
