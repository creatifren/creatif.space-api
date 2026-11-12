<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Insight\InsightBestTimes;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightCsv;
use App\Support\Insight\InsightScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /insight/best-times[?days=90][&channel_ids=<id>,<id>]
 *
 * The three weekday + hour slots in which each channel's own posts drew
 * the most engagement on average, over the last `days` days (7–92,
 * default 90) — what the MCP tool get_best_times reads. From the
 * workspace's posts only, never a benchmark; fewer than 10 posts with
 * engagement → `not_enough_data`. Pro / Max (402 plan_required on Free).
 */
class InsightBestTimesController extends Controller
{
    public const DEFAULT_DAYS = 90;

    public function __invoke(Request $request, InsightCollector $collector): JsonResponse
    {
        [$data] = $this->read($request, $collector);

        return response()->json([
            'data' => self::rows($data),
            'meta' => [
                'period' => ['from' => $data['period']['from'], 'to' => $data['period']['to']],
                'timezone' => (string) config('app.timezone'),
                'basis' => 'from your posts',
                'ranked_by' => 'average engagement per post',
                'min_posts' => InsightBestTimes::MIN_POSTS,
            ],
        ]);
    }

    /** GET /insight/best-times.csv — one row per channel and slot (rank 1–3), or one "not_enough_data" row. */
    public function csv(Request $request, InsightCollector $collector): StreamedResponse
    {
        [$data] = $this->read($request, $collector);
        $rows = [];
        foreach (self::rows($data) as $c) {
            if ($c['status'] !== 'ok') {
                $rows[] = [$c['platform'], $c['username'] ?? $c['network'], $c['status'], '', '', '', '', '', $c['posts_analyzed']];

                continue;
            }
            foreach ($c['slots'] as $i => $slot) {
                $rows[] = [$c['platform'], $c['username'] ?? $c['network'], 'ok', $i + 1, $slot['weekday'], $slot['hour'], $slot['posts'], $slot['avg_engagement'], $c['posts_analyzed']];
            }
        }

        return InsightCsv::download(
            "insight-best-times-{$data['period']['from']}-to-{$data['period']['to']}.csv",
            ['platform', 'channel', 'status', 'rank', 'weekday', 'hour', 'posts', 'avg_engagement', 'posts_analyzed'],
            $rows,
        );
    }

    /**
     * @return array{0: array<string, mixed>}
     */
    private function read(Request $request, InsightCollector $collector): array
    {
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'min:7', 'max:92']])['days'] ?? self::DEFAULT_DAYS);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $request->merge([
            'from' => $today->subDays($days - 1)->toDateString(),
            'to' => $today->toDateString(),
            'month' => null,
        ]);

        $scope = InsightScope::fromRequest($request);

        return [$collector->collectCached($scope->owner, $scope->accounts, $scope->period)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function rows(array $data): array
    {
        return array_map(fn (array $c) => [
            'channel_id' => $c['id'],
            'platform' => $c['platform'],
            'network' => $c['network'],
            'username' => $c['username'],
            ...$c['best_times'],
        ], $data['channels']);
    }
}
