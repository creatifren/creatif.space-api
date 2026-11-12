<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Insight\InsightCache;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightScope;
use App\Support\Insight\InsightSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The numbers of an Insight report without the PDF — what the MCP tool
 * get_insight_summary reads (token scope `read`). Same collector, same
 * rules: network numbers or null, never an estimate.
 *
 * GET /insight/summary?month=2026-10 | ?from=2026-09-15&to=2026-10-14
 *     [&channel_ids=<id>,<id>]
 *
 * Reading a period walks every channel's feed at the relay (one metrics
 * call per post), so an answer is kept CACHE_MINUTES per workspace +
 * period + channels.
 */
class InsightSummaryController extends Controller
{
    /** The Insight cache's lifetime (InsightCache): new numbers retire it sooner. */
    public const CACHE_MINUTES = InsightCache::TTL_MINUTES;

    public function __invoke(Request $request, InsightCollector $collector): JsonResponse
    {
        $scope = InsightScope::fromRequest($request);

        $data = InsightSummary::present($collector->collectCached($scope->owner, $scope->accounts, $scope->period));

        return response()->json(['data' => $data]);
    }
}
