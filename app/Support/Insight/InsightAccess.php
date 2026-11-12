<?php

namespace App\Support\Insight;

use App\Enums\PlanKey;
use App\Models\User;
use App\Support\Workspace;

/**
 * Who may take "Made with Creatif Space" off a report. Insight itself is
 * gated by plans v2 (PlanQuota::requireFeature($user, 'insight') — Pro and
 * Max); the branding line is the one thing Free always keeps, even on a
 * plan row that switches Insight on.
 */
final class InsightAccess
{
    public static function canHideBranding(User $user): bool
    {
        return Workspace::owner($user)->plan()->key !== PlanKey::Free;
    }
}
