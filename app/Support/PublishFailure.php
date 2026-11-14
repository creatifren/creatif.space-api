<?php

namespace App\Support;

use App\Jobs\FailUnconfirmedPublishes as FailUnconfirmed;
use Carbon\CarbonInterface;
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
        // YouTube on Outstand's managed keys: the shared upload pool opens again at retryAt.
        'capacity_pending' => 'rate_limited',
        'auth' => 'auth',
        'duplicate' => 'duplicate',
        'needs_video' => 'media',
        // The network took images from this account but refused its video upload (Bluesky 401).
        'video_upload_refused' => 'media',
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
        // The reviewer's seeded demo channel (DemoSocialProvider): nothing goes out.
        'demo_channel' => 'demo',
        'other' => 'other',
        // A refusal that says nothing. The coarse code stays "unknown", as
        // PostHog has always had it.
        'network_rejected' => 'unknown',
    ];

    /** Specific patterns first: "quota" before a generic limit, a missing board before "media". */
    private const PATTERNS = [
        // Our own marker, never a platform's words: checked first.
        'not_confirmed' => '/\[creatif:not_confirmed\]/',
        'demo_channel' => '/\[creatif:demo_channel\]/',
        // Outstand's stable YouTube codes ("[youtube_upload_error:release_pending; retryAt=…]",
        // docs: Publishing Rate Limits › Managed Keys: shared upload capacity), before the word matches.
        'capacity_pending' => '/\[youtube_upload_error:(release_pending|org_limit_reached)\b/',
        'video_upload_refused' => '/\bvideo upload failed\b.*\b40[13]\b|\bfailed to upload video:?\s*40[13]\b/i',
        'quota_exceeded' => '/\[youtube_upload_error:(daily_limit_reached|provider_quota_exhausted)\b|\b(quota|daily (upload )?limit|upload limit|uploadLimitExceeded)\b/i',
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

    /**
     * Every reason_code (a target's `fail_code`), the stable list an app
     * maps to its own copy.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::COARSE);
    }

    /** The coarse error_code for a reason_code (unknown for one this class never gave). */
    public static function coarse(?string $reasonCode): ?string
    {
        return $reasonCode === null ? null : (self::COARSE[$reasonCode] ?? 'unknown');
    }

    /**
     * Reason codes that mean the network no longer accepts the account's
     * sign-in: the channel needs a reconnect (ChannelHealth). Not
     * video_upload_refused: that account still posts images.
     */
    public const RECONNECT = ['auth'];

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
        return in_array($reasonCode, ['rate_limited', 'quota_exceeded', 'capacity_pending', 'platform_unavailable'], true);
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
            'capacity_pending' => "{$n}'s shared upload capacity was full several times in a row, so it was not sent and not charged. Send it again in a little while.",
            'video_upload_refused' => $network === 'bluesky'
                ? 'Bluesky refused the video upload (images from this account work). Bluesky only takes video from accounts with a verified email address: verify it in Bluesky › Settings › Account, then send it again.'
                : "{$n} refused the video upload. Check the account on {$n}, then send it again.",
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
            'demo_channel' => 'Demo channel: nothing is posted. This channel is sample data for the review; no network was contacted and no Credits were spent.',
            'not_confirmed' => "{$n} did not confirm the post within ".FailUnconfirmed::AFTER_MINUTES.' minutes, so it was stopped and not charged. Check the account on '.$n.' (on Bluesky: that its app password is still valid), then send it again.',
            default => "{$n} refused it. Try again, or contact support with the post link.",
        };
    }

    /**
     * What a destination that is waiting to be sent again (Pending with a
     * retry_at) is doing, for a person: shown instead of a silent wait.
     */
    public static function waitingReason(string $reasonCode, ?string $network, ?CarbonInterface $retryAt): string
    {
        $n = self::networkName($network);
        $when = $retryAt === null ? 'shortly' : 'at '.$retryAt->copy()->utc()->format('H:i').' UTC';

        return match ($reasonCode) {
            'capacity_pending' => "{$n}'s shared upload capacity is full right now. It is sent again automatically {$when}.",
            'quota_exceeded' => "{$n}'s upload limit is reached for now. It is sent again automatically {$when}.",
            'platform_unavailable' => "{$n} was unavailable. It is sent again automatically {$when}.",
            default => "{$n} is limiting posts right now. It is sent again automatically {$when}.",
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
