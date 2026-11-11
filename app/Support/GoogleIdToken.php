<?php

namespace App\Support;

use App\Actions\Auth\GoogleIdentity;
use App\Exceptions\InvalidGoogleIdToken;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Verifies a Google ID token offline, against Google's published keys.
 *
 * The Android app gets the token from Credential Manager with our WEB
 * client id as `serverClientId`, so `aud` is that id (services.google.
 * client_id); extra audiences can be allowed with
 * GOOGLE_NATIVE_EXTRA_AUDIENCES. Checked: RS256 signature by `kid`, `iss`,
 * `aud`, `exp` (60 s leeway), `iat` not in the future, a verified `email`,
 * and `sub`.
 *
 * The key set is cached for as long as Google's Cache-Control says; the
 * only network call is a refresh — when the cache is empty, or once when a
 * token names a `kid` we do not hold (Google rotated its keys).
 */
class GoogleIdToken
{
    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public const LEEWAY_SECONDS = 60;

    private const CACHE_KEY = 'google-id-token:jwks';

    /** An unknown kid forces a refresh at most this often (a forged kid must not become a request to Google per call). */
    private const FORCED_REFRESH_KEY = 'google-id-token:jwks-forced';

    private const FORCED_REFRESH_SECONDS = 30;

    /**
     * @throws InvalidGoogleIdToken the token is not one we accept (message: why, for the log)
     * @throws RuntimeException Google's key set could not be fetched
     */
    public function verify(string $token): GoogleIdentity
    {
        $claims = $this->claims($token);

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new InvalidGoogleIdToken('wrong iss');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (array_intersect($audiences, self::allowedAudiences()) === []) {
            throw new InvalidGoogleIdToken('wrong aud');
        }

        $now = now()->getTimestamp();
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] + self::LEEWAY_SECONDS <= $now) {
            throw new InvalidGoogleIdToken('expired');
        }
        if (! is_numeric($claims['iat'] ?? null) || (int) $claims['iat'] > $now + self::LEEWAY_SECONDS) {
            throw new InvalidGoogleIdToken('iat in the future');
        }

        $email = $claims['email'] ?? null;
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidGoogleIdToken('no email');
        }
        $verified = $claims['email_verified'] ?? null;
        if ($verified !== true && $verified !== 'true') {
            throw new InvalidGoogleIdToken('email not verified');
        }

        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '') {
            throw new InvalidGoogleIdToken('no sub');
        }

        $name = $claims['name'] ?? $claims['given_name'] ?? null;
        $picture = $claims['picture'] ?? null;

        return new GoogleIdentity(
            id: $sub,
            email: $email,
            name: is_string($name) && $name !== '' ? $name : null,
            avatar: is_string($picture) && $picture !== '' ? $picture : null,
        );
    }

    /**
     * A Google-signed JWT's claims: RS256 signature by a published `kid`,
     * and the library's exp/nbf/iat checks. Nothing about iss/aud/email —
     * each caller checks the claims its token type must carry.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidGoogleIdToken
     * @throws RuntimeException Google's key set could not be fetched
     */
    public function claims(string $token): array
    {
        $header = $this->header($token);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new InvalidGoogleIdToken('alg is not RS256');
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new InvalidGoogleIdToken('no kid');
        }

        $cached = Cache::get(self::CACHE_KEY);
        $jwks = is_array($cached) ? $cached : $this->fetchKeySet();
        // Google rotated its keys since we cached them: refresh once.
        if (is_array($cached) && ! $this->holds($jwks, $kid) && Cache::add(self::FORCED_REFRESH_KEY, true, self::FORCED_REFRESH_SECONDS)) {
            $jwks = $this->fetchKeySet();
        }
        if (! $this->holds($jwks, $kid)) {
            throw new InvalidGoogleIdToken('unknown kid');
        }

        return $this->decode($token, $jwks);
    }

    /** @return list<string> */
    public static function allowedAudiences(): array
    {
        $extra = array_map('trim', explode(',', (string) config('services.google.native_extra_audiences', '')));

        return array_values(array_filter([(string) config('services.google.client_id'), ...$extra], fn ($a) => $a !== ''));
    }

    /** @return array<string, mixed> */
    private function header(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidGoogleIdToken('malformed');
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidGoogleIdToken('malformed header');
        }

        return is_array($header) ? $header : throw new InvalidGoogleIdToken('malformed header');
    }

    /**
     * Signature and the library's own time checks; the claims as an array.
     *
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed>
     */
    private function decode(string $token, array $jwks): array
    {
        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = now()->getTimestamp();

        try {
            $payload = JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
        } catch (Throwable $e) {
            throw new InvalidGoogleIdToken('rejected: '.class_basename($e).': '.$e->getMessage());
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }

        return json_decode((string) json_encode($payload), true) ?: [];
    }

    /** @param  array<string, mixed>  $jwks */
    private function holds(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] ?? [] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Google's key set, fetched now and cached for its max-age.
     *
     * @return array<string, mixed>
     */
    private function fetchKeySet(): array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->get(self::JWKS_URL);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Google key set unreachable: '.$e->getMessage(), 0, $e);
        }

        $jwks = $response->json();
        if (! $response->successful() || ! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new RuntimeException('Google key set unusable (HTTP '.$response->status().')');
        }

        Cache::put(self::CACHE_KEY, $jwks, self::maxAge($response->header('Cache-Control')));

        return $jwks;
    }

    /** Seconds the key set may be kept: Cache-Control max-age, within 1 minute .. 1 day; 1 hour when absent. */
    private static function maxAge(string $cacheControl): int
    {
        if (preg_match('/(?:^|[,\s])max-age=(\d+)/i', $cacheControl, $m) !== 1) {
            return 3600;
        }

        return max(60, min(86400, (int) $m[1]));
    }
}
