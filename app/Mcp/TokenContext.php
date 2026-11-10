<?php

namespace App\Mcp;

use App\Models\User;

/**
 * Who is calling with a bearer token, and what that token may do.
 *
 * Built by McpTokenResolver for an OAuth access token or a personal API
 * key; read by EnforceTokenScope (scope, rate limit, audit) and by the
 * introspection endpoint. Never holds the token itself.
 */
final class TokenContext
{
    public const KIND_OAUTH = 'oauth';

    public const KIND_API_KEY = 'api_key';

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly User $user,
        public readonly string $kind,
        public readonly string $tokenId,
        public readonly array $scopes,
        public readonly ?string $clientId,
        public readonly ?string $clientName,
        public readonly ?int $expiresAt,
        public readonly ?int $issuedAt,
    ) {}

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** One rate-limit bucket and one audit identity per token. */
    public function key(): string
    {
        return $this->kind.':'.$this->tokenId;
    }
}
