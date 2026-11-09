<?php

namespace App\Support;

use App\Enums\SocialAccountStatus;
use App\Enums\SpaceStatus;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

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
        /* withTrashed on both halves. A file in the Trash is still an object
           in the bucket and still billed, so it still counts — that is what
           makes "empty the Trash to free 340 MB" a true sentence rather than
           an invitation to park 20 GB in the bin for a month. Without it the
           soft delete would have silently handed everyone free storage. */
        /* A pending row only holds its space while its presigned URL can
           still be used. Past the window the PUT can no longer start, so the
           reservation is dead weight until PruneStaleUploads removes it —
           counting it would lock people out of uploads they never made. */
        $current = (int) $user->files()
            ->withTrashed()
            ->where(fn ($q) => $q
                ->where('status', File::STATUS_READY)
                ->orWhere(fn ($q) => $q
                    ->where('status', File::STATUS_PENDING)
                    ->where('created_at', '>=', now()->subMinutes(File::PRESIGN_TTL_MINUTES))))
            ->sum('size_bytes');

        $history = (int) FileVersion::query()
            ->whereIn('file_id', $user->files()->withTrashed()->select('id'))
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
        $plan = static::planStorageLimit($user);

        // The plan's storage plus whatever the account rents in Credits.
        return $plan === null ? null : $plan + Credits::rentedBytes($user);
    }

    /** What the plan alone includes — the base the rented blocks sit on. */
    public static function planStorageLimit(User $user): ?int
    {
        $limit = $user->plan()->quota('storage_bytes');

        return $limit === null ? null : $limit * Workspace::seats($user);
    }

    /**
     * Over the ceiling: a block lapsed, or the plan shrank. Uploads stop
     * until it fits; nothing is deleted and every link keeps working.
     */
    public static function readOnly(User $user): bool
    {
        $limit = static::storageLimit($user);

        return $limit !== null && static::storageUsed($user) > $limit;
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
     * The publishing allowance: publishes a period, per seat and pooled like
     * storage. Null means no limit. What has been used is
     * Credits::allowanceUsed.
     */
    public static function socialPostsLimit(User $user): ?int
    {
        $owner = Workspace::owner($user);
        $limit = $owner->plan()->quota('social_posts_monthly');

        return $limit === null ? null : $limit * Workspace::seats($owner);
    }

    /**
     * Room to send to $destinations accounts: whatever the allowance does
     * not cover must be in Credits.
     */
    public static function canPostSocial(User $user, int $destinations = 1): bool
    {
        return Credits::balance($user) >= Credits::sendPrice($user, $destinations);
    }

    /**
     * Asking a client to approve is a paid feature. Refused only when a new
     * request starts — links already sent keep working through a downgrade,
     * and approving your own post is open to every plan.
     *
     * @throws ValidationException
     */
    public static function requireClientApprovals(User $user, string $field = 'approval'): void
    {
        if (! Workspace::owner($user)->plan()->feature('client_approvals')) {
            throw ValidationException::withMessages([
                $field => 'Client approvals are part of Pro and Max.',
            ]);
        }
    }

    /**
     * Room to connect one more social account? Free stops at three. An
     * account already connected is never cut off by a downgrade — only
     * adding one is refused.
     */
    public static function canConnectSocialAccount(User $user): bool
    {
        $owner = Workspace::owner($user);
        $limit = $owner->plan()->quota('social_accounts');

        return $limit === null
            || $owner->socialAccounts()->where('status', SocialAccountStatus::Connected)->count() < $limit;
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
