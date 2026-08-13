<?php

namespace App\Support;

use App\Enums\SpaceStatus;
use App\Models\User;

/**
 * Plan limits, read from the plans table. No number lives here: a user's
 * plan comes from their active subscription (none = Free), and the ceiling
 * comes from that plan's `quotas`.
 *
 * A null ceiling means no limit — every check below treats it as "always
 * room", which is what a paid plan buys.
 */
class PlanQuota
{
    /**
     * Spaces created and not deleted (draft + published + archived).
     */
    public static function totalUsed(User $user): int
    {
        return $user->spaces()->count();
    }

    /**
     * Active = published. Archiving frees a slot.
     */
    public static function activeUsed(User $user): int
    {
        return $user->spaces()->where('status', SpaceStatus::Published)->count();
    }

    public static function totalLimit(User $user): ?int
    {
        return $user->plan()->quota('spaces_total');
    }

    public static function activeLimit(User $user): ?int
    {
        return $user->plan()->quota('spaces_active');
    }

    /**
     * Room for one more Space at all?
     */
    public static function canCreate(User $user): bool
    {
        $limit = static::totalLimit($user);

        return $limit === null || static::totalUsed($user) < $limit;
    }

    /**
     * Room for one more *published* Space?
     */
    public static function canPublish(User $user): bool
    {
        $limit = static::activeLimit($user);

        return $limit === null || static::activeUsed($user) < $limit;
    }

    /**
     * @return array{active_used: int, active_limit: int|null, total_used: int, total_limit: int|null, plan: string}
     */
    public static function meta(User $user): array
    {
        return [
            'active_used' => static::activeUsed($user),
            'active_limit' => static::activeLimit($user),
            'total_used' => static::totalUsed($user),
            'total_limit' => static::totalLimit($user),
            'plan' => $user->plan()->key->value,
        ];
    }
}
