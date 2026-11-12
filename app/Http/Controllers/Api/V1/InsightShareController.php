<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InsightShareLink;
use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightPeriod;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Shareable Insight links (Pro / Max): a read-only page of the workspace's
 * numbers for one period, for a client who has no account.
 *
 *   POST   /api/v1/insight/share {from, to, channels?} → 201 {id, url, token, expires_at, …}
 *   GET    /api/v1/insight/share                      → the workspace's links (no tokens)
 *   DELETE /api/v1/insight/share/{id}                 → 204, revoked
 *
 * The token is shown once, in the 201; only its hash is kept. A link lives
 * InsightShareLink::TTL_DAYS days. Another workspace's id answers 404
 * (ScopeToWorkspace). The page itself: GET /api/v1/public/insight/{token}.
 */
class InsightShareController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        PlanQuota::requireFeature($user, 'insight');

        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'channels' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $owner = Workspace::owner($user);
        $period = InsightPeriod::fromInput(['from' => $v['from'], 'to' => $v['to']], '');

        $available = InsightClients::accounts($owner)->pluck('ulid')->all();
        $asked = array_values(array_filter(array_map('trim', explode(',', (string) ($v['channels'] ?? '')))));
        if ($asked !== [] && array_diff($asked, $available) !== []) {
            throw ValidationException::withMessages(['channels' => 'One of these channels is not connected to this workspace.']);
        }
        $channels = $asked !== [] ? $asked : $available;
        if ($channels === []) {
            throw ValidationException::withMessages(['channels' => 'Connect a channel first: there is nothing to share.']);
        }

        $link = InsightShareLink::issue($owner, $user, $period, $channels);

        return response()->json(['data' => $this->body($link) + [
            'token' => $link->plainToken,
            'url' => InsightShareLink::url((string) $link->plainToken),
        ]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        PlanQuota::requireFeature($request->user(), 'insight');

        $links = InsightShareLink::query()
            ->where('owner_id', Workspace::owner($request->user())->id)
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $links->map(fn (InsightShareLink $l) => $this->body($l))->values()]);
    }

    public function destroy(InsightShareLink $insightShareLink): Response
    {
        if (! $insightShareLink->revoked()) {
            $insightShareLink->forceFill(['revoked_at' => now()])->save();
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(InsightShareLink $link): array
    {
        return [
            'id' => $link->ulid,
            'period' => [
                'from' => $link->period_from->toDateString(),
                'to' => $link->period_to->toDateString(),
                'label' => $link->period()->label('en'),
            ],
            'channel_ids' => $link->channel_ids,
            'status' => $link->revoked() ? 'revoked' : ($link->expired() ? 'expired' : 'active'),
            'expires_at' => $link->expires_at->utc()->toIso8601String(),
            'revoked_at' => $link->revoked_at?->utc()->toIso8601String(),
            'created_at' => $link->created_at?->utc()->toIso8601String(),
        ];
    }
}
