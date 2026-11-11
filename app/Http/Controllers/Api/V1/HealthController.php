<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * GET /api/v1/health/deep — for an external uptime monitor. /up stays the
 * cheap liveness probe; this one touches every dependency a send needs:
 *
 * - db: a round trip to the database
 * - queue: how long the oldest waiting job has waited (database queue)
 * - storage: the R2 bucket answers (a HEAD on a key that need not exist)
 * - outstand: the publishing relay's API answers (any non-5xx)
 * - mail: a real driver is configured (and Resend has its key)
 *
 * Bearer HEALTH_TOKEN (or X-Health-Token) only; without the env set the
 * route does not exist (404). 200 when every check is ok, 503 otherwise.
 * Never user data: only ok / latency / a short reason per check.
 */
class HealthController extends Controller
{
    public function deep(Request $request): JsonResponse
    {
        $expected = (string) config('services.ops.health_token');
        abort_if($expected === '', 404);

        $given = (string) ($request->bearerToken() ?? $request->header('X-Health-Token', ''));
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $checks = [
            'db' => $this->check(fn () => DB::select('select 1')),
            'queue' => $this->check(fn () => $this->queue()),
            'storage' => $this->check(fn () => Storage::disk('s3')->exists('.health')),
            'outstand' => $this->check(fn () => $this->outstand()),
            'mail' => $this->check(fn () => $this->mail()),
        ];
        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'checked_at' => now()->utc()->toIso8601String(),
        ], $ok ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    /**
     * @param  Closure(): (string|null|mixed)  $probe  a string return is a failure reason
     * @return array{ok: bool, latency_ms: int, detail?: string}
     */
    private function check(Closure $probe): array
    {
        $start = hrtime(true);
        try {
            $result = $probe();
            $failure = is_string($result) ? $result : null;
        } catch (Throwable $e) {
            $failure = class_basename($e);
        }

        return array_filter([
            'ok' => $failure === null,
            'latency_ms' => (int) round((hrtime(true) - $start) / 1e6),
            'detail' => $failure,
        ], fn ($v) => $v !== null);
    }

    /** Oldest waiting job's age against the limit; only the database queue can say. */
    private function queue(): ?string
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        $table = (string) config('queue.connections.database.table', 'jobs');
        $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
        $lag = $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) $oldest);
        $max = (int) config('services.ops.queue_max_lag', 600);

        return $lag > $max ? "oldest job waiting {$lag}s (limit {$max}s)" : null;
    }

    private function outstand(): ?string
    {
        $status = Http::timeout(5)->connectTimeout(3)
            ->withToken((string) config('services.outstand.key'))
            ->get(rtrim((string) config('services.outstand.base_url'), '/').'/social-accounts', ['limit' => 1])
            ->status();

        return $status >= 500 ? "answered {$status}" : null;
    }

    private function mail(): ?string
    {
        $driver = (string) config('mail.default');

        if (in_array($driver, ['log', 'array'], true) && app()->isProduction()) {
            return "driver is {$driver}";
        }
        if ($driver === 'resend' && (string) config('services.resend.key') === '') {
            return 'Resend has no API key';
        }

        return null;
    }
}
