<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InsightShareLink;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPdfBuilder;
use App\Support\Insight\InsightSummary;
use App\Support\PlanQuota;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/public/insight/{token} — the shareable Insight page's data,
 * for {frontend}/report/{token}. No sign-in: the token is the credential.
 *
 * Only the report: period, totals, per-channel numbers (network and public
 * handle, no ids), weekly reach, top posts, workflow, notes (through the
 * Insight cache, 5 minutes). Never the
 * workspace, its owner, plan, account ids, other periods or channels.
 *
 * 410 {expired: true} after 30 days (the page shows its "expired" state);
 * 404 for an unknown or revoked token, or a workspace no longer on a plan
 * with Insight — the same answer for all three, so nothing is confirmed.
 */
class PublicInsightController extends Controller
{
    public function __invoke(string $token, InsightCollector $collector): JsonResponse
    {
        $link = InsightShareLink::findByToken($token);

        if ($link === null || $link->revoked() || $link->owner === null || ! PlanQuota::hasFeature($link->owner, 'insight')) {
            return response()->json(['message' => 'This report link is not available.'], 404);
        }

        if ($link->expired()) {
            return response()->json(['expired' => true, 'message' => 'This report link has expired.'], 410);
        }

        $report = self::publicView(InsightSummary::present($collector->collectCached(
            $link->owner,
            InsightPdfBuilder::accounts($link->owner, $link->channel_ids),
            $link->period(),
        )));

        return response()->json(['data' => $report + [
            'expires_at' => $link->expires_at->utc()->toIso8601String(),
        ]])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }

    /**
     * The summary with every internal identifier taken out.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function publicView(array $summary): array
    {
        $summary['channels'] = array_map(function (array $c): array {
            unset($c['id']);

            return $c;
        }, $summary['channels']);

        return $summary;
    }
}
