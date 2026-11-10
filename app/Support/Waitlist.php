<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The public waitlists (double opt-in, no table). The confirm link carries
 * everything — email, list, utm — encrypted and MAC'd with APP_KEY and an
 * expiry, so nothing is stored until the person confirms, and then only in
 * Resend.
 */
final class Waitlist
{
    /** List → env-configured Resend segment. Only these can be joined. */
    public const LISTS = ['mcp'];

    public const DAYS = 7;

    /**
     * @param  array<string, string>  $utm
     */
    public static function token(string $email, string $list, array $utm = []): string
    {
        return Crypt::encryptString((string) json_encode([
            'e' => $email,
            'l' => $list,
            'u' => $utm,
            'x' => now()->addDays(self::DAYS)->timestamp,
        ]));
    }

    /**
     * @return array{email: string, list: string, utm: array<string, string>}|null null when forged, unknown or expired
     */
    public static function read(?string $token): ?array
    {
        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['e'] ?? null) || ! in_array($data['l'] ?? null, self::LISTS, true)
            || ! is_int($data['x'] ?? null) || $data['x'] < now()->timestamp) {
            return null;
        }

        return ['email' => $data['e'], 'list' => $data['l'], 'utm' => array_filter((array) ($data['u'] ?? []), 'is_string')];
    }

    public static function segment(string $list): string
    {
        return (string) config("services.resend.waitlist_segments.{$list}");
    }

    /**
     * Put the address in the list's Resend segment. Idempotent: an existing
     * contact, or one already in the segment, is success. False on a
     * failure (logged); true when no segment is configured (logged), so the
     * confirm page never depends on deploy order.
     */
    public static function subscribe(string $email, string $list): bool
    {
        $segment = self::segment($list);
        if ($segment === '') {
            Log::warning('waitlist.segment_missing', ['list' => $list, 'env' => 'RESEND_WAITLIST_'.strtoupper($list).'_SEGMENT']);

            return true;
        }

        try {
            $resend = Http::withToken((string) config('services.resend.key'))
                ->acceptJson()
                ->timeout(10)
                ->baseUrl('https://api.resend.com');

            $created = $resend->post('/contacts', ['email' => $email, 'unsubscribed' => false]);
            if ($created->failed() && ! self::alreadyThere($created->status(), (string) $created->body())) {
                Log::warning('waitlist.contact_failed', ['list' => $list, 'status' => $created->status(), 'body' => mb_substr((string) $created->body(), 0, 500)]);

                return false;
            }

            $added = $resend->post('/contacts/'.rawurlencode($email).'/segments/'.rawurlencode($segment));
            if ($added->failed() && ! self::alreadyThere($added->status(), (string) $added->body())) {
                Log::warning('waitlist.segment_failed', ['list' => $list, 'status' => $added->status(), 'body' => mb_substr((string) $added->body(), 0, 500)]);

                return false;
            }
        } catch (Throwable $e) {
            Log::warning('waitlist.resend_unreachable', ['list' => $list, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    private static function alreadyThere(int $status, string $body): bool
    {
        return in_array($status, [409, 422], true) && str_contains(strtolower($body), 'already');
    }
}
