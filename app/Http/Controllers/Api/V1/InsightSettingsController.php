<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PlanQuota;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Insight: the monthly email switch.
 *
 *   GET   /api/v1/me/insight-settings → {monthly_email, available}
 *   PATCH /api/v1/me/insight-settings {monthly_email: bool}
 *
 * The workspace owner's switch (a teammate gets 403: the report is the
 * owner's). Switching it on needs Insight (Pro / Max; Free gets the
 * plans-v2 402); switching it off is always allowed. A workspace that drops
 * to Free keeps the switch but gets no email (SendMonthlyInsightReport
 * checks the plan again on the day).
 */
class InsightSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->body($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        Workspace::ownerOnly($user);

        $validated = $request->validate(['monthly_email' => ['required', 'boolean']]);

        if ($validated['monthly_email']) {
            PlanQuota::requireFeature($user, 'insight', 'monthly_email');
        }

        $user->forceFill(['insight_monthly_email' => (bool) $validated['monthly_email']])->save();

        return response()->json(['data' => $this->body($request)]);
    }

    /**
     * @return array{monthly_email: bool, available: bool}
     */
    private function body(Request $request): array
    {
        $user = $request->user();

        return [
            // A model made in this request may not carry the column's default yet.
            'monthly_email' => (bool) ($user->getAttributes()['insight_monthly_email'] ?? false),
            // Whether the plan sends it (the app greys the switch out when false).
            'available' => PlanQuota::hasFeature($user, 'insight') && ! Workspace::isMember($user),
        ];
    }
}
