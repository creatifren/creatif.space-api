<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\HealthProbe;
use App\Support\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/health — public, rate-limited, no token:
 *
 *   {"status": "ok"|"degraded", "db": "ok"|"fail", "queue": …, "storage": …,
 *    "queue_lag_seconds": int|null, "scheduler_last_tick": ISO 8601|null,
 *    "version": "<commit sha>"|null}
 *
 * 200 when every check passes, 503 otherwise. queue_lag_seconds is how long
 * the oldest waiting job has waited (null off the database queue);
 * scheduler_last_tick is when the scheduler last ran (it beats every
 * minute; null before its first run). Both are for watching, not part of
 * the status. No secrets, no user data, no failure reasons (those are on
 * the token-only /health/deep). The checks and the lag are kept
 * CACHE_SECONDS so a burst of callers costs one database round trip and
 * one storage request, not one each.
 */
class PublicHealthController extends Controller
{
    public const CACHE_SECONDS = 15;

    public function __invoke(HealthProbe $probe): JsonResponse
    {
        /** @var array{checks: array<string, bool>, queue_lag: int|null} $cached */
        $cached = Cache::remember('health:public:v2', now()->addSeconds(self::CACHE_SECONDS), fn () => [
            'checks' => $probe->run(),
            'queue_lag' => HealthProbe::queueLag(),
        ]);
        $checks = $cached['checks'];
        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            ...array_map(fn (bool $pass) => $pass ? 'ok' : 'fail', $checks),
            'queue_lag_seconds' => $cached['queue_lag'],
            'scheduler_last_tick' => SchedulerHeartbeat::last()?->toIso8601String(),
            'version' => $probe->version(),
        ], $ok ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
