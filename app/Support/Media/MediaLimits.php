<?php

namespace App\Support\Media;

use App\Enums\PlanKey;
use App\Models\User;
use App\Support\Workspace;
use Carbon\CarbonImmutable;

/**
 * The media tier of an account and the numbers that go with it, read from
 * config/media.php. A trial is Pro whatever it is a trial of; Team (not
 * sold) is Pro. Members use their workspace owner's tier.
 */
final class MediaLimits
{
    public const FREE = 'free';

    public const PRO = 'pro';

    public const MAX = 'max';

    /** @return 'free'|'pro'|'max' */
    public static function tier(User $user): string
    {
        $owner = Workspace::owner($user);
        $subscription = $owner->activeSubscription();

        if ($subscription === null) {
            return self::FREE;
        }

        if ($subscription->isTrialing()) {
            return self::PRO;
        }

        return $subscription->plan->key === PlanKey::Max ? self::MAX : self::PRO;
    }

    /**
     * When the workspace's owner dropped from a paid plan to Free: the end
     * of their last subscription's entitlement (grace, else period end;
     * the last update when those are missing or still ahead, e.g. an
     * early expiry). Null while paid, or for an account that never paid.
     */
    public static function downgradedAt(User $user): ?CarbonImmutable
    {
        $owner = Workspace::owner($user);
        if ($owner->activeSubscription() !== null) {
            return null;
        }

        $last = $owner->subscriptions()->latest('id')->first();
        if ($last === null) {
            return null;
        }

        $end = $last->grace_ends_at ?? $last->current_period_end;
        $end = $end === null || $end->isFuture() ? $last->updated_at : $end;

        return $end === null ? null : CarbonImmutable::instance($end);
    }

    public static function paid(User $user): bool
    {
        return self::tier($user) !== self::FREE;
    }

    public static function keepQuota(User $user): int
    {
        return (int) config('media.tiers.'.self::tier($user).'.keep_quota_bytes', 0);
    }

    /** Free's total cap on everything stored; null for paid tiers. */
    public static function totalCap(User $user): ?int
    {
        $cap = config('media.tiers.'.self::tier($user).'.total_bytes');

        return $cap === null ? null : (int) $cap;
    }

    /** upgrade_pro | upgrade_max | contact_sales */
    public static function nextStep(User $user): string
    {
        return (string) config('media.tiers.'.self::tier($user).'.next_step');
    }

    public static function technicalMax(): int
    {
        return (int) config('media.technical_max_bytes');
    }

    /**
     * The largest single file of this kind the tier may upload, and whether
     * that number is a plan cap (safe to show) or only the hidden technical
     * ceiling.
     *
     * @return array{bytes: int, plan_cap: bool}
     */
    public static function perFileMax(User $user, string $mime): array
    {
        $technical = self::technicalMax();

        $cap = match (FileSignature::family($mime)) {
            'image' => (int) config('media.image_max_bytes'),
            'video' => config('media.tiers.'.self::tier($user).'.video_max_bytes'),
            default => (int) config('media.document_max_bytes'),
        };

        if ($cap === null) {
            return ['bytes' => $technical, 'plan_cap' => false];
        }

        return ['bytes' => min((int) $cap, $technical), 'plan_cap' => (int) $cap <= $technical];
    }
}
