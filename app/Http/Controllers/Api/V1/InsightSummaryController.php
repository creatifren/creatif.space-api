<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightCollector;
use App\Support\Insight\InsightPeriod;
use App\Support\Insight\InsightSummary;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

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
    public const CACHE_MINUTES = 10;

    public function __invoke(Request $request, InsightCollector $collector): JsonResponse
    {
        $user = $request->user();

        // Pro / Max (plans v2): 402 plan_required, feature "insight".
        PlanQuota::requireFeature($user, 'insight');

        $validated = $request->validate([
            'month' => ['required_without_all:from,to', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['required_without:month', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_without:month', 'nullable', 'date_format:Y-m-d'],
            'channel_ids' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $period = InsightPeriod::fromInput($validated, '');
        $owner = Workspace::owner($user);
        $accounts = InsightClients::accounts($owner);

        $asked = array_values(array_filter(array_map('trim', explode(',', (string) ($validated['channel_ids'] ?? '')))));
        if ($asked !== []) {
            $unknown = array_diff($asked, $accounts->pluck('ulid')->all());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['channel_ids' => 'These channels are not connected to this workspace: '.implode(', ', $unknown).'.']);
            }
            $accounts = $accounts->filter(fn ($a) => in_array($a->ulid, $asked, true))->values();
        }

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['channel_ids' => 'Connect a channel first: there is nothing to report on.']);
        }

        $key = 'insight-summary:'.$owner->id.':'.$period->kind.':'.$period->from->toDateString().':'.$period->to->toDateString()
            .':'.sha1($accounts->pluck('ulid')->sort()->implode(','));

        $data = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn () => InsightSummary::present(
            $collector->collect($owner, $accounts, $period),
        ));

        return response()->json(['data' => $data]);
    }
}
