<?php

namespace App\Support;

use App\Notifications\WaitlistOnTheList;
use App\Notifications\WaitlistPriority;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * The public waitlists (double opt-in, no table) and "invite 3, move up".
 *
 * - The confirm link carries email, list, utm and the inviter's ref code,
 *   encrypted and MAC'd with APP_KEY, with an expiry: nothing is stored
 *   before someone confirms.
 * - Resend is the record: each confirmed contact carries ref_code,
 *   referred_by, confirmed_at, referral_count, priority, referral_counted.
 * - A ref code is an HMAC of the address under APP_KEY (8 URL-safe chars):
 *   the same person always gets the same code, and nobody can work one out.
 *   Resend cannot look a contact up by a property, so the cache keeps
 *   code → {encrypted address, count, priority} (database store; never
 *   expires). The status endpoint reads only that, no PII.
 */
final class Waitlist
{
    /** Only these can be joined. */
    public const LISTS = ['mcp'];

    public const DAYS = 7;

    /** Confirmed referrals that move someone to the priority batch. */
    public const PRIORITY_AT = 3;

    public const REF_PATTERN = '/^[A-Za-z0-9_-]{8}$/';

    /** Throwaway inboxes: a referral from one does not count. */
    public const DISPOSABLE = [
        '10minutemail.com', '20minutemail.com', 'discard.email', 'dispostable.com', 'emailondeck.com',
        'fakeinbox.com', 'getnada.com', 'guerrillamail.com', 'guerrillamail.net', 'mailinator.com',
        'maildrop.cc', 'mailnesia.com', 'mintemail.com', 'mohmal.com', 'moakt.com', 'sharklasers.com',
        'spamgourmet.com', 'temp-mail.org', 'tempmail.com', 'tempmail.dev', 'tempmailo.com',
        'throwawaymail.com', 'trashmail.com', 'yopmail.com', 'yopmail.net',
    ];

    /**
     * @param  array<string, string>  $utm
     */
    public static function token(string $email, string $list, array $utm = [], ?string $ref = null): string
    {
        return Crypt::encryptString((string) json_encode([
            'e' => $email,
            'l' => $list,
            'u' => $utm,
            'r' => $ref,
            'x' => now()->addDays(self::DAYS)->timestamp,
        ]));
    }

    /**
     * @return array{email: string, list: string, utm: array<string, string>, ref: string|null}|null null when forged, unknown or expired
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
        $ref = $data['r'] ?? null;

        return [
            'email' => $data['e'],
            'list' => $data['l'],
            'utm' => array_filter((array) ($data['u'] ?? []), 'is_string'),
            'ref' => is_string($ref) && preg_match(self::REF_PATTERN, $ref) ? $ref : null,
        ];
    }

    /** This address's ref code: stable, and not derivable without APP_KEY. */
    public static function refCode(string $email): string
    {
        $mac = hash_hmac('sha256', 'waitlist-ref|'.strtolower($email), (string) config('app.key'), true);

        return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 8);
    }

    public static function segment(string $list): string
    {
        return (string) config("services.resend.waitlist_segments.{$list}");
    }

    /**
     * Progress for a ref code, or null when unknown. No PII.
     *
     * @return array{referral_count: int, priority: bool}|null
     */
    public static function status(string $code): ?array
    {
        $row = self::mirror($code);

        return $row === null ? null : ['referral_count' => $row['count'], 'priority' => $row['priority']];
    }

    /**
     * Confirm a sign-up: contact + properties + segment in Resend, the
     * referral, and the "you're on the list" email. Idempotent per address:
     * a second confirm changes nothing and sends nothing.
     *
     * @param  array{email: string, list: string, utm: array<string, string>, ref: string|null}  $entry
     * @return array{code: string, first: bool, referred: bool}|null null when Resend failed
     */
    public static function confirm(array $entry): ?array
    {
        $email = $entry['email'];
        $code = self::refCode($email);
        $lock = Cache::lock('waitlist-confirm:'.hash('sha256', $email), 15);

        try {
            $lock->block(10);

            $first = self::mirror($code) === null;
            $referred = false;

            // No mirror (first confirm, or a cleared cache): Resend decides.
            // Already confirmed there → restore the mirror, change nothing.
            if ($first) {
                $props = (array) (self::contact($email)['properties'] ?? []);
                if (filled($props['confirmed_at'] ?? null)) {
                    self::remember($code, $email, (int) ($props['referral_count'] ?? 0), ($props['priority'] ?? null) === 'true');
                    $first = false;
                }
            }

            if ($first) {
                $referrer = self::countableReferrer($entry['ref'], $email);
                self::saveContact($email, [
                    'ref_code' => $code,
                    'referred_by' => $referrer !== null ? (string) $entry['ref'] : '',
                    'confirmed_at' => now()->toIso8601String(),
                    'referral_count' => 0,
                    'priority' => 'false',
                    'referral_counted' => 'false',
                ]);

                $segment = self::segment($entry['list']);
                if ($segment === '') {
                    Log::warning('waitlist.segment_missing', ['list' => $entry['list'], 'env' => 'RESEND_WAITLIST_'.strtoupper($entry['list']).'_SEGMENT']);
                } else {
                    self::addToSegment($email, $segment);
                }
                self::remember($code, $email, 0, false);

                // The referred contact's own flag, under its confirm lock.
                if ($referrer !== null && Cache::add('waitlist-counted:'.hash('sha256', $email), true)) {
                    self::credit((string) $entry['ref'], $referrer);
                    self::saveContact($email, ['referral_counted' => 'true']);
                    $referred = true;
                }

                Notification::route('mail', $email)->notify(new WaitlistOnTheList(self::shareUrl($code)));
            }

            return ['code' => $code, 'first' => $first, 'referred' => $referred];
        } catch (Throwable $e) {
            Log::warning('waitlist.confirm_failed', ['list' => $entry['list'], 'error' => $e->getMessage()]);

            return null;
        } finally {
            $lock->release();
        }
    }

    public static function shareUrl(string $code): string
    {
        return rtrim((string) config('app.public_url'), '/').'/mcp?ref='.$code;
    }

    /** The referrer's address when this referral may count, else null. */
    private static function countableReferrer(?string $ref, string $email): ?string
    {
        if ($ref === null || $ref === self::refCode($email)) {
            return null;
        }

        $row = self::mirror($ref);
        if ($row === null || strtolower($row['email']) === strtolower($email)) {
            return null;
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach (self::DISPOSABLE as $bad) {
            if ($domain === $bad || str_ends_with($domain, '.'.$bad)) {
                return null;
            }
        }

        return $row['email'];
    }

    /** One more confirmed referral for $code; the priority batch at PRIORITY_AT, announced once. */
    private static function credit(string $code, string $referrer): void
    {
        $lock = Cache::lock('waitlist-referrer:'.$code, 15);
        $lock->block(10);

        try {
            $row = self::mirror($code);
            $remote = self::contact($referrer);
            $before = max((int) ($remote['properties']['referral_count'] ?? 0), $row['count'] ?? 0);
            $count = $before + 1;
            $priority = $count >= self::PRIORITY_AT;
            $wasPriority = ($row['priority'] ?? false) || ($remote['properties']['priority'] ?? null) === 'true';

            self::saveContact($referrer, ['referral_count' => $count, 'priority' => $priority ? 'true' : 'false']);
            self::remember($code, $referrer, $count, $priority);

            if ($priority && ! $wasPriority && Cache::add('waitlist-priority:'.$code, true)) {
                Notification::route('mail', $referrer)->notify(new WaitlistPriority);
                ProductEvents::waitlistPriorityReached($referrer, $count);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{email: string, count: int, priority: bool}|null
     */
    private static function mirror(string $code): ?array
    {
        $row = Cache::get('waitlist-ref:'.$code);
        if (! is_array($row) || ! is_string($row['e'] ?? null)) {
            return null;
        }

        try {
            $email = Crypt::decryptString($row['e']);
        } catch (DecryptException) {
            return null;
        }

        return ['email' => $email, 'count' => (int) ($row['c'] ?? 0), 'priority' => (bool) ($row['p'] ?? false)];
    }

    private static function remember(string $code, string $email, int $count, bool $priority): void
    {
        Cache::forever('waitlist-ref:'.$code, ['e' => Crypt::encryptString($email), 'c' => $count, 'p' => $priority]);
    }

    private static function resend(): PendingRequest
    {
        return Http::withToken((string) config('services.resend.key'))
            ->acceptJson()
            ->timeout(10)
            ->baseUrl('https://api.resend.com');
    }

    /**
     * @return array<string, mixed>|null null when Resend has no such contact
     */
    private static function contact(string $email): ?array
    {
        $response = self::resend()->get('/contacts/'.rawurlencode($email));
        if ($response->status() === 404) {
            return null;
        }
        self::ok($response, 'contact_read');

        return (array) $response->json();
    }

    /**
     * Create the contact with these properties, or update them on an
     * existing one.
     *
     * @param  array<string, string|int>  $properties
     */
    private static function saveContact(string $email, array $properties): void
    {
        $created = self::resend()->post('/contacts', ['email' => $email, 'unsubscribed' => false, 'properties' => $properties]);
        if (! $created->failed()) {
            return;
        }
        if (! self::alreadyThere($created)) {
            self::ok($created, 'contact_create');
        }

        self::ok(self::resend()->patch('/contacts/'.rawurlencode($email), ['properties' => $properties]), 'contact_update');
    }

    private static function addToSegment(string $email, string $segment): void
    {
        $added = self::resend()->post('/contacts/'.rawurlencode($email).'/segments/'.rawurlencode($segment));
        if ($added->failed() && ! self::alreadyThere($added)) {
            self::ok($added, 'segment_add');
        }
    }

    private static function ok(Response $response, string $step): void
    {
        if ($response->failed()) {
            throw new RuntimeException("Resend {$step} answered {$response->status()}: ".mb_substr((string) $response->body(), 0, 300));
        }
    }

    private static function alreadyThere(Response $response): bool
    {
        return in_array($response->status(), [409, 422], true) && str_contains(strtolower((string) $response->body()), 'already');
    }
}
