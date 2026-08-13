<?php

namespace App\Jobs;

use App\Models\SpaceEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Raw traffic is kept 90 days, then dropped — the daily chart lives on in
 * space_daily_stats, and nobody needs to know which browser hash looked at
 * a photo last spring.
 *
 * Deleted in chunks rather than one statement: a backlog after a long
 * outage must not lock the table for a minute in the middle of the night.
 * The cap means a very large backlog takes a few nights to clear, which is
 * fine — nothing reads those rows.
 */
class PruneSpaceEvents implements ShouldQueue
{
    use Queueable;

    public const KEEP_DAYS = 90;

    private const CHUNK = 5000;

    private const MAX_CHUNKS = 20;

    public int $tries = 1;

    public function handle(): void
    {
        $cutoff = now()->subDays(self::KEEP_DAYS);

        for ($i = 0; $i < self::MAX_CHUNKS; $i++) {
            // Ids first, then delete by key: DELETE ... LIMIT is a MySQL
            // extension, and the test suite runs on SQLite.
            $ids = SpaceEvent::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            SpaceEvent::query()->whereIn('id', $ids)->delete();
        }
    }
}
