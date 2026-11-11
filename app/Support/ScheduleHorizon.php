<?php

namespace App\Support;

use App\Exceptions\ScheduleBeyondHorizon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * How far ahead a relay takes a scheduled post. Outstand refuses a slot past
 * its horizon with a 400; its documentation could not be read when this was
 * written, so the number is config (services.<relay>.max_schedule_days,
 * OUTSTAND_MAX_SCHEDULE_DAYS) and the refusal is also recognised by its
 * wording, in case the configured number is ever too generous.
 * Null days = no known limit.
 */
final class ScheduleHorizon
{
    /** Words Outstand's 400 uses when the slot is too far out. */
    private const REFUSAL = '/schedul\w*.{0,80}(too far|in the future|ahead|days|horizon|maximum|max\b|exceed|later than|beyond)'
        .'|(too far|horizon|maximum|max\b|exceed|later than|beyond).{0,80}schedul/is';

    public static function days(?string $relay): ?int
    {
        $days = config('services.'.($relay ?: config('services.social.provider')).'.max_schedule_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    public static function latest(?string $relay): ?CarbonImmutable
    {
        $days = self::days($relay);

        return $days === null ? null : CarbonImmutable::now()->utc()->addDays($days);
    }

    public static function beyond(?string $relay, ?CarbonInterface $at): bool
    {
        $latest = self::latest($relay);

        return $latest !== null && $at !== null && $at->copy()->utc()->greaterThan($latest);
    }

    /**
     * @throws ScheduleBeyondHorizon
     */
    public static function require(?string $relay, ?CarbonInterface $at, string $field = 'scheduled_at'): void
    {
        if (self::beyond($relay, $at)) {
            throw new ScheduleBeyondHorizon((int) self::days($relay), self::latest($relay)->startOfDay(), $field);
        }
    }

    /** The relay refused a create because the slot is too far out. */
    public static function isRefusal(Throwable $e): bool
    {
        if ($e instanceof ScheduleBeyondHorizon) {
            return true;
        }
        if (! $e instanceof RequestException || ! in_array($e->response->status(), [400, 422], true)) {
            return false;
        }

        return (bool) preg_match(self::REFUSAL, $e->response->body());
    }
}
