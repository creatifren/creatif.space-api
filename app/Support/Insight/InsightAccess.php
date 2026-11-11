<?php

namespace App\Support\Insight;

use App\Enums\PlanKey;
use App\Exceptions\PlanFeatureRequired;
use App\Models\User;
use App\Support\Trial;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Insight is Pro / Max. The plan is the workspace owner's, as everywhere.
 *
 * TODO(plans v2, creatifren/creatif.space-api#49): once that PR is on main,
 * replace allowed()/refusal() with PlanQuota::requireFeature($user,
 * 'insight') — it throws PlanFeatureRequired, which renders this same 402
 * body. Until then the gate reads the plan key, and also honours a plan row
 * whose `features.insight` is switched on, so it keeps working as v2's rows
 * land.
 */
final class InsightAccess
{
    private const PAID = [PlanKey::Pro, PlanKey::Max];

    public static function allowed(User $user): bool
    {
        $plan = Workspace::owner($user)->plan();

        return in_array($plan->key, self::PAID, true) || $plan->feature('insight');
    }

    /**
     * "Made with Creatif Space" comes off on Pro and Max only; on Free it is
     * always printed, whatever the request said.
     */
    public static function canHideBranding(User $user): bool
    {
        return in_array(Workspace::owner($user)->plan()->key, self::PAID, true);
    }

    /** The plans-v2 refusal: 402 plan_required, feature "insight". */
    public static function refusal(User $user): JsonResponse
    {
        $url = PlanFeatureRequired::billingUrl();
        $next = Trial::eligible(Workspace::owner($user))
            ? 'Start the '.Trial::days().'-day Pro trial: '.$url
            : 'Upgrade: '.$url;
        $message = 'Insights and analytics is on Pro and Max. '.$next;

        return response()->json([
            'message' => $message,
            'reason' => PlanFeatureRequired::CODE,
            'feature' => 'insight',
            'required_plan' => 'pro',
            'code' => PlanFeatureRequired::CODE,
            'billing_url' => $url,
            'errors' => ['plan' => [$message]],
        ], 402);
    }
}
