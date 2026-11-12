<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every 429 from the v1 API carries, whichever limit said no:
 * X-RateLimit-Limit (the limit that was hit), X-RateLimit-Remaining (0),
 * Retry-After (seconds) and X-RateLimit-Reset (unix time). Laravel's
 * throttle middleware already sends these; the hand-made 429s (token
 * limiter, daily send cap, upload caps, reminders, the relay asking us to
 * wait) set them here, and EnsureRateLimitHeaders fills any gap.
 */
final class RateLimitHeaders
{
    /** @return array<string, string> */
    public static function for(int $limit, int $retryAfter): array
    {
        $retryAfter = max(1, $retryAfter);

        return [
            'X-RateLimit-Limit' => (string) max(0, $limit),
            'X-RateLimit-Remaining' => '0',
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Reset' => (string) (now()->getTimestamp() + $retryAfter),
        ];
    }

    /** Set them on a response, keeping any the source already chose. */
    public static function apply(Response $response, int $limit, int $retryAfter): Response
    {
        foreach (self::for($limit, $retryAfter) as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
