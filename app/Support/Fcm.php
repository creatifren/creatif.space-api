<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1. The service account comes from
 * FCM_CREDENTIALS: a path to the JSON key file, the JSON itself, or the
 * JSON in base64. Missing or unreadable = push is off (no errors).
 */
class Fcm
{
    public const SENT = 'sent';

    /** The phone is gone (app removed, token rotated): delete the token. */
    public const GONE = 'gone';

    public const FAILED = 'failed';

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    public static function credentials(): ?array
    {
        $raw = config('services.fcm.credentials');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (! str_starts_with($raw, '{')) {
            if (is_file($raw) && is_readable($raw)) {
                $raw = (string) file_get_contents($raw);
            } else {
                $decoded = base64_decode($raw, true);
                $raw = $decoded === false ? '' : $decoded;
            }
        }
        $json = json_decode($raw, true);
        if (! is_array($json) || ! is_string($json['project_id'] ?? null) || ! is_string($json['client_email'] ?? null) || ! is_string($json['private_key'] ?? null)) {
            return null;
        }

        return ['project_id' => $json['project_id'], 'client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * Send one notification to one phone.
     *
     * @param  array<string, string>  $data
     */
    public static function send(string $token, string $title, string $body, array $data = []): string
    {
        $creds = self::credentials();
        if ($creds === null) {
            return self::FAILED;
        }
        try {
            $access = self::accessToken($creds);
            if ($access === null) {
                return self::FAILED;
            }
            $res = Http::withToken($access)->timeout(10)->post(
                'https://fcm.googleapis.com/v1/projects/'.$creds['project_id'].'/messages:send',
                ['message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data === [] ? new \stdClass : $data,
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'default']],
                ]],
            );
        } catch (Throwable $e) {
            Log::warning('fcm.send_failed', ['error' => $e->getMessage()]);

            return self::FAILED;
        }
        if ($res->successful()) {
            return self::SENT;
        }
        $status = (string) data_get($res->json(), 'error.status', '');
        $code = (string) collect((array) data_get($res->json(), 'error.details', []))->pluck('errorCode')->filter()->first();
        if ($res->status() === 404 || $code === 'UNREGISTERED' || ($status === 'INVALID_ARGUMENT' && $code === 'INVALID_ARGUMENT')) {
            return self::GONE;
        }
        Log::warning('fcm.send_refused', ['status' => $res->status(), 'error' => $status ?: $code]);

        return self::FAILED;
    }

    /**
     * An OAuth access token for the service account (JWT bearer grant), cached ~50 min.
     *
     * @param  array{project_id: string, client_email: string, private_key: string}  $creds
     */
    private static function accessToken(array $creds): ?string
    {
        $key = 'fcm:access:'.sha1($creds['client_email']);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $now = time();
        $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64((string) json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signature = '';
        if (! openssl_sign($head.'.'.$claims, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('fcm.bad_key');

            return null;
        }
        $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $head.'.'.$claims.'.'.$b64($signature),
        ]);
        $token = $res->json('access_token');
        if (! $res->successful() || ! is_string($token)) {
            Log::warning('fcm.token_refused', ['status' => $res->status()]);

            return null;
        }
        Cache::put($key, $token, now()->addMinutes(50));

        return $token;
    }
}
