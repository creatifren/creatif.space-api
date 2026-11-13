<?php

namespace App\Support;

use App\Jobs\FailUnconfirmedPublishes as FailUnconfirmed;
use Illuminate\Support\Str;

/**
 * A platform's refusal, read once: a short code for analytics and a reason
 * a person can act on. The raw provider text is never part of either — it
 * can quote the caption or name the account — and is kept server-side on
 * the target (provider_error).
 *
 * Matching is on whole words and known phrases. The old substring match
 * read "generate", "separate" or "moderate" as a rate limit and "author"
 * as an auth problem.
 */
final class PublishFailure
{
    /**
     * Coarse codes, as PostHog has always had them (`error_code`), with a
     * specific `reason_code` alongside.
     *
     * @var array<string, string> reason_code => error_code
     */
    private const COARSE = [
        'rate_limited' => 'rate_limited',
        'quota_exceeded' => 'rate_limited',
        'auth' => 'auth',
        'duplicate' => 'duplicate',
        'needs_video' => 'media',
        'needs_media' => 'media',
        'media_spec' => 'media',
        'media_unreachable' => 'media',
        'missing_board' => 'missing_field',
        'missing_title' => 'missing_field',
        'content_too_long' => 'content',
        'content_rejected' => 'content',
        'platform_unavailable' => 'platform_unavailable',
        // Sent, but the relay never said published or failed (FailUnconfirmedPublishes).
        'not_confirmed' => 'unknown',
        'other' => 'other',
        // A refusal that says nothing. The coarse code stays "unknown", as
        // PostHog has always had it.
        'network_rejected' => 'unknown',
    ];

    /** Specific patterns first: "quota" before a generic limit, a missing board before "media". */
    private const PATTERNS = [
        // Our own marker, never a platform's words: checked first.
        'not_confirmed' => '/\[creatif:not_confirmed\]/',
        'quota_exceeded' => '/\b(quota|daily (upload )?limit|upload limit|uploadLimitExceeded)\b/i',
        'rate_limited' => '/\b(rate[ _-]?limit(ed)?|too many requests|429|throttl(ed|ing)|try again later|limit reached)\b/i',
        'missing_board' => '/\bboard(_?ids?)?\b.*\b(required|missing|not found|invalid|must)\b|\b(required|missing|no)\b.*\bboard/i',
        'missing_title' => '/\btitle\b.*\b(required|missing|empty|must)\b|\b(required|missing)\b.*\btitle\b/i',
        'auth' => '/\b(access token|token (has )?expired|expired token|unauthori[sz]ed|invalid[_ ]grant|re-?authori[sz]e|reconnect|permission(s)? (denied|missing)|not authori[sz]ed|oauth)\b/i',
        'duplicate' => '/\b(duplicate|already (been )?(posted|published))\b/i',
        'needs_video' => '/\b(requires? (a )?video|video (is )?required|only videos?|must be a video|no video)\b/i',
        'needs_media' => '/\b(requires? (an? )?(image|media|photo)|(image|media|photo)s? (is |are )?required|no media)\b/i',
        'media_unreachable' => '/\b(could not (download|fetch|retrieve)|failed to (download|fetch)|media url|url (is )?(not )?(accessible|reachable)|403 forbidden|404 not found)\b/i',
        'media_spec' => '/\b(aspect ratio|resolution|duration|too (large|big|short|long) (file|video|image)|file size|unsupported (format|codec|media|file)|invalid (media|image|video)|codec|bitrate|frame ?rate|dimensions?)\b/i',
        'content_too_long' => '/\b(too long|exceeds? (the )?(maximum|max|character)|character limit|max(imum)? length)\b/i',
        'content_rejected' => '/\b(spam|policy|violat(es|ion)|community guidelines|not allowed|inappropriate)\b/i',
        'platform_unavailable' => '/\b(timeout|timed out|unavailable|server error|internal error|5\d\d|bad gateway|service error|connection (refused|reset|closed)|could not resolve host|ECONNRESET|ECONNREFUSED|ETIMEDOUT|socket hang up|cURL error (6|7|28|35|52|56))\b/i',
    ];

    /**
     * @return array{error_code: string, reason_code: string, reason: string}
     */
    public static function classify(?string $raw, ?string $network = null): array
    {
        $raw = trim((string) $raw);
        $code = 'network_rejected';

        if ($raw !== '' && $raw !== 'the platform refused it') {
            $code = 'other';
            foreach (self::PATTERNS as $candidate => $pattern) {
                if (preg_match($pattern, $raw) === 1) {
                    $code = $candidate;
                    break;
                }
            }
        }

        return [
            'error_code' => self::COARSE[$code],
            'reason_code' => $code,
            'reason' => self::reason($code, $network, $raw),
        ];
    }

    /** Codes whose own wording from the platform is the most useful part. */
    private const WITH_DETAIL = ['other', 'media_spec', 'content_rejected', 'content_too_long'];

    /**
     * The platform's own words, tidied for a person: machine tags such as
     * "[youtube_upload_error:…; retryAt=…]" out, whitespace folded, capped.
     */
    public static function detail(?string $raw): ?string
    {
        $text = trim((string) preg_replace(['/\[[^\]]*\]/', '/\s+/'], ['', ' '], (string) $raw));

        return $text === '' || $text === 'the platform refused it' ? null : Str::limit($text, 200);
    }

    /** Whether waiting and trying again can fix it. */
    public static function retryable(string $reasonCode): bool
    {
        return in_array($reasonCode, ['rate_limited', 'quota_exceeded', 'platform_unavailable'], true);
    }

    public static function reason(string $code, ?string $network, ?string $raw = null): string
    {
        $n = self::networkName($network);
        $detail = in_array($code, self::WITH_DETAIL, true) ? self::detail($raw) : null;

        if ($detail !== null) {
            return match ($code) {
                'other' => "{$n} refused it: {$detail}",
                default => self::reason($code, $network)." ({$n}: {$detail})",
            };
        }

        return match ($code) {
            'rate_limited' => "{$n} is limiting how often this account can post right now. Try again in a little while.",
            'quota_exceeded' => "{$n} has hit its daily upload limit for this account. Try again tomorrow.",
            'auth' => "{$n} needs you to reconnect this account in Creatif Space → Channels.",
            'duplicate' => "{$n} refused it as a duplicate of a recent post. Change the caption or media and send again.",
            'needs_video' => "{$n} only takes video. Attach one video file.",
            'needs_media' => "{$n} needs an image or a video. Attach one and send again.",
            'media_spec' => "{$n} refused the media file (size, length, format or aspect ratio). Check {$n}'s media requirements and try another file.",
            'media_unreachable' => "{$n} could not download the media. Re-attach the file and send again.",
            'missing_board' => 'Pinterest needs a board for this Pin. Choose a board and send again.',
            'missing_title' => "{$n} needs a title for this post.",
            'content_too_long' => "The caption is too long for {$n}. Shorten it and send again.",
            'content_rejected' => "{$n} refused the content under its rules.",
            'platform_unavailable' => "{$n} was unavailable. Try again in a few minutes.",
            'network_rejected' => "{$n} refused it without saying why. Try again, or contact support.",
            'not_confirmed' => "{$n} did not confirm the post within ".FailUnconfirmed::AFTER_MINUTES.' minutes, so it was stopped and not charged. Check the account on '.$n.' (on Bluesky: that its app password is still valid), then send it again.',
            default => "{$n} refused it. Try again, or contact support with the post link.",
        };
    }

    public static function networkName(?string $network): string
    {
        return match ($network) {
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'linkedin' => 'LinkedIn',
            'x' => 'X',
            null, '' => 'The platform',
            default => ucfirst($network),
        };
    }
}
