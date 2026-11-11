<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\HealthProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/health — public, rate-limited, no token:
 *
 *   {"status": "ok"|"degraded", "db": "ok"|"fail", "queue": …, "storage": …,
 *    "version": "<commit sha>"|null}
 *
 * 200 when every check passes, 503 otherwise. No secrets, no user data, no
 * failure reasons (those are on the token-only /health/deep). The answer is
 * kept CACHE_SECONDS so a burst of callers costs one database round trip
 * and one storage request, not one each.
 */
class PublicHealthController extends Controller
{
    public const CACHE_SECONDS = 15;

    public function __invoke(HealthProbe $probe): JsonResponse
    {
        $checks = Cache::remember('health:public', now()->addSeconds(self::CACHE_SECONDS), fn () => $probe->run());
        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            ...array_map(fn (bool $pass) => $pass ? 'ok' : 'fail', $checks),
            'version' => $probe->version(),
        ], $ok ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
