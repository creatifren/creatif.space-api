<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The scheduler's pulse: every minute the scheduler writes the time it ran
 * (routes/console.php), and GET /api/v1/health reports it as
 * scheduler_last_tick, so a stopped scheduler (no cron, a dead worker box)
 * shows up as a tick that stops moving. A time only — nothing else.
 */
class SchedulerHeartbeat
{
    public const KEY = 'scheduler:last_tick';

    public function __invoke(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    /** When the scheduler last ran, or null if it has not been seen (or the cache is unreadable). */
    public static function last(): ?CarbonImmutable
    {
        try {
            $at = Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_int($at) ? CarbonImmutable::createFromTimestamp($at, 'UTC') : null;
    }
}
