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
     * Bytes counted against the storage quota. Pending rows count too —
     * otherwise presigning N huge files in parallel bypasses the check.
     *
     * Old versions are charged for as well. They are real objects sitting in
     * the bucket, and billing them is what makes "you can free up 340 MB"
     * a true sentence rather than an invitation to hoard.
     */
    public static function storageUsed(User $user): int
    {
        $current = (int) $user->files()
            ->whereIn('status', [\App\Models\File::STATUS_PENDING, \App\Models\File::STATUS_READY])
            ->sum('size_bytes');

        $history = (int) \App\Models\FileVersion::query()
            ->whereIn('file_id', $user->files()->select('id'))
            ->sum('size_bytes');

        return $current + $history;
    }

    /**
     * Storage is a per-seat number, pooled: Team at 3 seats shares 3× the
     * plan's storage_bytes across the workspace. Free and Premium have one
     * seat, so the multiplier is invisible there.
     */
    public static function storageLimit(User $user): ?int
    {
        $limit = $user->plan()->quota('storage_bytes');

        return $limit === null ? null : $limit * Workspace::seats($user);
    }

    /**
     * Room for $bytes more?
     */
    public static function canStore(User $user, int $bytes): bool
    {
        $limit = static::storageLimit($user);

        return $limit === null || static::storageUsed($user) + $bytes <= $limit;
    }

    /**
     * Social posts created this calendar month, cancelled ones excluded —
     * cancelling gives the slot back. Counted at creation (scheduled or
     * immediate), so scheduling ahead spends quota up front.
     *
     * Pooled across the workspace: the owner's and every active member's
     * posts draw from the same monthly pot, mirroring storage.
     */
    public static function socialPostsUsed(User $user): int
    {
        $owner = Workspace::owner($user);

        $userIds = $owner->teamMembers()
            ->where('status', \App\Enums\TeamMemberStatus::Active)
            ->pluck('member_id')
            ->push($owner->id);

        return \App\Models\SocialPost::query()
            ->whereIn('user_id', $userIds)
            ->where('status', '!=', \App\Enums\SocialPostStatus::Cancelled)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Per-seat and pooled, like storage. Zero is a real ceiling (Free),
     * null means no limit.
     */
    public static function socialPostsLimit(User $user): ?int
    {
        $owner = Workspace::owner($user);
        $limit = $owner->plan()->quota('social_posts_monthly');

        return $limit === null ? null : $limit * Workspace::seats($owner);
    }

    public static function canPostSocial(User $user): bool
    {
        $limit = static::socialPostsLimit($user);

        return $limit === null || static::socialPostsUsed($user) < $limit;
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
