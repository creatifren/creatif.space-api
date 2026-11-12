<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /insight/top-posts?month=2026-10 | ?from=…&to=… [&channel_ids=<id>,<id>]
 *
 * The top five posts of the period by engagement (likes + comments + shares
 * + saves, as the network reports them), across the chosen channels — what
 * the MCP tool list_top_posts reads. Same collector and cache as the
 * summary; a post whose network sends no engagement is left out, never
 * ranked as 0. Pro / Max (402 plan_required on Free).
 */
class InsightTopPostsController extends Controller
{
    public function __invoke(Request $request, InsightCollector $collector): JsonResponse
    {
        $scope = InsightScope::fromRequest($request);
        $data = $collector->collectCached($scope->owner, $scope->accounts, $scope->period);

        return response()->json([
            'data' => array_map(fn (array $p) => [
                'channel_id' => $p['channel_id'],
                'platform' => $p['platform'],
                'network' => $p['network'],
                'username' => $p['username'],
                'post_id' => $p['post_id'],
                'posted_at' => $p['posted_at'],
                'caption' => $p['caption'],
                'url' => $p['url'],
                'thumbnail_url' => $p['thumb_url'],
                'engagement' => $p['engagement'],
                'likes' => $p['likes'],
                'comments' => $p['comments'],
                'shares' => $p['shares'],
                'saves' => $p['saves'],
                'reach' => $p['reach'],
                'impressions' => $p['views'],
            ], $data['top_ranked']),
            'meta' => [
                'period' => ['from' => $data['period']['from'], 'to' => $data['period']['to']],
                'ranked_by' => 'engagement',
                'limit' => InsightCollector::TOP_RANKED,
            ],
        ]);
    }
}
