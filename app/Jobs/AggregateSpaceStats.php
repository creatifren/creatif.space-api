<?php

namespace App\Jobs;

use App\Enums\SpaceEventType;
use App\Models\SpaceDailyStat;
use App\Models\SpaceEvent;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roll raw events into one row per Space per day, so the daily chart still
 * works after the 90-day prune has taken the events away.
 *
 * Does yesterday *and* today, not only yesterday: a job that only ever
 * looked back one day would leave a permanent hole the first time the queue
 * was down overnight. Today's row is rewritten again tomorrow with the full
 * count — the upsert makes a re-run a correction, never a doubling.
 *
 * Crawlers are excluded from `views` and counted separately. A creator's
 * traffic figure has to be people.
 */
class AggregateSpaceStats implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function handle(): void
    {
        foreach ([now()->subDay(), now()] as $day) {
            $this->aggregate($day->startOfDay(), $day->copy()->endOfDay());
        }
    }

    private function aggregate(CarbonInterface $from, CarbonInterface $to): void
    {
        // toBase(): these rows are aggregates, not SpaceEvent models, and
        // hydrating them as models would invent properties that no column
        // backs.
        $rows = SpaceEvent::query()
            ->selectRaw('space_id')
            ->selectRaw('SUM(CASE WHEN type = ? AND is_ai_crawler = 0 THEN 1 ELSE 0 END) AS views', [SpaceEventType::View->value])
            ->selectRaw('COUNT(DISTINCT CASE WHEN is_ai_crawler = 0 THEN visitor_hash END) AS unique_visitors')
            ->selectRaw('SUM(CASE WHEN is_ai_crawler = 1 THEN 1 ELSE 0 END) AS ai_crawler_hits')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('space_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $spaceId = (int) $row->space_id;

            SpaceDailyStat::query()->updateOrCreate(
                ['space_id' => $spaceId, 'date' => $from->toDateString()],
                [
                    'views' => (int) $row->views,
                    'unique_visitors' => (int) $row->unique_visitors,
                    'ai_crawler_hits' => (int) $row->ai_crawler_hits,
                    'top_referrers' => $this->topReferrers($spaceId, $from, $to),
                ],
            );
        }
    }

    /**
     * The eight biggest sources for the day — enough for Free to render the
     * sources card without ever touching space_events.
     *
     * @return list<array{host: string, count: int}>
     */
    private function topReferrers(int $spaceId, CarbonInterface $from, CarbonInterface $to): array
    {
        return SpaceEvent::query()
            ->selectRaw('referrer_host, COUNT(*) AS hits')
            ->where('space_id', $spaceId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('referrer_host')
            ->where('is_ai_crawler', false)
            ->groupBy('referrer_host')
            ->orderByDesc('hits')
            ->limit(8)
            ->toBase()
            ->get()
            ->map(fn ($row): array => [
                'host' => (string) $row->referrer_host,
                'count' => (int) $row->hits,
            ])
            ->all();
    }
}
