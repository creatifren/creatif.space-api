<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Insight\InsightBestTimes;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'min:7', 'max:92']])['days'] ?? self::DEFAULT_DAYS);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $request->merge([
            'from' => $today->subDays($days - 1)->toDateString(),
            'to' => $today->toDateString(),
            'month' => null,
        ]);

        $scope = InsightScope::fromRequest($request);
        $data = $collector->collectCached($scope->owner, $scope->accounts, $scope->period);

        return response()->json([
            'data' => array_map(fn (array $c) => [
                'channel_id' => $c['id'],
                'platform' => $c['platform'],
                'network' => $c['network'],
                'username' => $c['username'],
                ...$c['best_times'],
            ], $data['channels']),
            'meta' => [
                'period' => ['from' => $data['period']['from'], 'to' => $data['period']['to']],
                'timezone' => (string) config('app.timezone'),
                'basis' => 'from your posts',
                'ranked_by' => 'average engagement per post',
                'min_posts' => InsightBestTimes::MIN_POSTS,
            ],
        ]);
    }
}
