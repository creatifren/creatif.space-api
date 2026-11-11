<?php

namespace App\Support;

use App\Exceptions\InvalidGoogleIdToken;
use RuntimeException;

/**
 * The OIDC token Pub/Sub puts on a push request (Google Play's real-time
 * developer notifications). Signed by Google like an ID token; here `aud`
 * must be the push subscription's audience and `email` its service
 * account, verified. Both are required config: unset, nothing passes.
 */
class GooglePushToken
{
    public function __construct(private GoogleIdToken $tokens) {}

    /**
     * @return array<string, mixed> the claims
     *
     * @throws InvalidGoogleIdToken
     * @throws RuntimeException Google's key set could not be fetched
     */
    public function verify(string $jwt): array
    {
        $audience = (string) config('services.google_play.rtdn_audience');
        $email = strtolower((string) config('services.google_play.rtdn_service_account_email'));
        if ($audience === '' || $email === '') {
            throw new InvalidGoogleIdToken('RTDN audience or service account not configured');
        }

        $claims = $this->tokens->claims($jwt);

        if (! in_array($claims['iss'] ?? null, GoogleIdToken::ISSUERS, true)) {
            throw new InvalidGoogleIdToken('wrong iss');
        }
        $aud = $claims['aud'] ?? null;
        if (! in_array($audience, is_array($aud) ? $aud : [$aud], true)) {
            throw new InvalidGoogleIdToken('wrong aud');
        }
        if (! is_string($claims['email'] ?? null) || strtolower($claims['email']) !== $email) {
            throw new InvalidGoogleIdToken('wrong email');
        }
        $verified = $claims['email_verified'] ?? null;
        if ($verified !== true && $verified !== 'true') {
            throw new InvalidGoogleIdToken('email not verified');
        }
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] + GoogleIdToken::LEEWAY_SECONDS <= now()->getTimestamp()) {
            throw new InvalidGoogleIdToken('expired');
        }

        return $claims;
    }
}
