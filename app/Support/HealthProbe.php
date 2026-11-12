<?php

namespace App\Support;

use App\Services\ErrorTracking;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The three things every request and every send leans on — database, queue,
 * file storage — each answered as a plain pass / fail. Used by the public
 * GET /api/v1/health and the scheduled `health:selfcheck`.
 *
 * Deliberately says nothing else: no reasons, latencies, hostnames or
 * counts, so a public caller learns only "up" or "not". The detailed,
 * token-only view is GET /api/v1/health/deep (HealthController).
 */
class HealthProbe
{
    public const CHECKS = ['db', 'queue', 'storage'];

    /**
     * @return array{db: bool, queue: bool, storage: bool}
     */
    public function run(): array
    {
        return [
            'db' => $this->passes(fn () => DB::select('select 1')),
            'queue' => $this->passes(fn () => $this->queue()),
            // The bucket answering is the check; the key need not exist.
            'storage' => $this->passes(function () {
                Storage::disk('s3')->exists('.health');

                return true;
            }),
        ];
    }

    /**
     * The deployed commit (APP_RELEASE, else the checkout's HEAD), or null.
     */
    public function version(): ?string
    {
        return ErrorTracking::release();
    }

    /**
     * A probe passes when it neither throws nor returns false.
     */
    private function passes(Closure $probe): bool
    {
        try {
            return $probe() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The database queue: the oldest waiting job has waited no longer than
     * HEALTH_QUEUE_MAX_LAG. Any other driver: it answers a size query.
     */
    private function queue(): bool
    {
        if (config('queue.default') !== 'database') {
            Queue::size();

            return true;
        }

        return (int) self::queueLag() <= (int) config('services.ops.queue_max_lag', 600);
    }

    /**
     * Seconds the oldest waiting job has waited (0 when none waits), for
     * the database queue; null for another driver or when it cannot be read.
     */
    public static function queueLag(): ?int
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            $table = (string) config('queue.connections.database.table', 'jobs');
            $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
        } catch (Throwable) {
            return null;
        }

        return $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) $oldest);
    }
}
