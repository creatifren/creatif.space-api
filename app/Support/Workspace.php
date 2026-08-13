<?php

namespace App\Support;

use App\Enums\TeamMemberStatus;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Whose data a request is acting on.
 *
 * The whole Team feature reduces to this one question. Every owner-scoped
 * query used to read `$request->user()`; now it reads the workspace owner,
 * which is the same person for everybody except an active team member.
 *
 * What a member gets, and what they deliberately do not:
 *
 *   Spaces, the editor      the owner's, and they can write unless viewer
 *   Files (Drive browser)   the owner's, read-only always — connecting or
 *                           revoking a Drive is an account action, and a
 *                           member revoking the owner's Drive is a
 *                           disaster with no upside
 *   Approval, Analytics     the owner's, read-only
 *   Earnings, withdrawals   owner only. Money is not a seat feature
 *   Subscription, handle,
 *   profile, referral       owner only — identity and billing belong to
 *                           the account holder
 *
 * Their own handle, profile and public page are untouched: a member is a
 * person with an account, not a sub-login.
 *
 * Seat ceilings have two sources and they do not disagree:
 * `plans.quotas.seats` is how many are *included*, `subscriptions.seats`
 * is how many were *bought* — Plan::priceFor() already charges only for
 * the difference. The check is against the purchased total, falling back
 * to the included count when there is no subscription at all.
 *
 * ponytail: no per-Space permissions, no per-member activity attribution
 * (that needs an actor column on Spaces), and admin behaves exactly like
 * editor. Each of those is a real feature, and none of them is needed to
 * make a Team plan honest.
 */
final class Workspace
{
    /**
     * Memoised per request, keyed by user id. A membership cannot change
     * mid-request, so this is safe and saves the lookup on every check.
     *
     * @var array<int, TeamMember|null>
     */
    private static array $memberships = [];

    /**
     * The account this person's work belongs to: their own, or the owner
     * of the team they are an active member of.
     */
    public static function owner(User $user): User
    {
        $membership = self::membership($user);

        return $membership === null ? $user : $membership->owner;
    }

    /**
     * "owner" for their own account, otherwise the role they were given.
     */
    public static function role(User $user): string
    {
        return self::membership($user)?->role->value ?? 'owner';
    }

    public static function isMember(User $user): bool
    {
        return self::membership($user) !== null;
    }

    /**
     * Everything except `viewer` may build. Owners always may.
     */
    public static function canWrite(User $user): bool
    {
        return self::role($user) !== 'viewer';
    }

    /**
     * Money, billing and identity are the owner's alone. Called at the top
     * of the endpoints a seat does not buy.
     */
    public static function ownerOnly(User $user): void
    {
        abort_if(self::isMember($user), 403, 'That belongs to the account owner.');
    }

    /**
     * How many seats this account has: what was bought, or what the plan
     * includes when nothing was.
     */
    public static function seats(User $owner): int
    {
        $subscription = $owner->activeSubscription();

        if ($subscription !== null) {
            return max($subscription->seats, 1);
        }

        return $owner->plan()->quota('seats') ?? 1;
    }

    /**
     * Seats currently spoken for. The owner holds one — "3 people included"
     * means the owner and two others, which is also why Free and Premium at
     * one seat have no room to invite anybody. Invited counts too, because
     * a seat is reserved the moment it is offered.
     */
    public static function seatsUsed(User $owner): int
    {
        return 1 + $owner->teamMembers()
            ->whereIn('status', [TeamMemberStatus::Invited, TeamMemberStatus::Active])
            ->count();
    }

    /**
     * Forget what was memoised — for tests, and for the moment a membership
     * is created or revoked inside a single request.
     */
    public static function forget(): void
    {
        self::$memberships = [];
    }

    /**
     * This person's active membership, if any — and null the moment the
     * owner's plan stops carrying more than one seat. Access ends with the
     * subscription rather than being revoked: nothing is deleted, and the
     * member falls back to their own account, exactly like a Space
     * downgrade.
     */
    private static function membership(User $user): ?TeamMember
    {
        if (array_key_exists($user->id, self::$memberships)) {
            return self::$memberships[$user->id];
        }

        $membership = TeamMember::query()
            ->where('member_id', $user->id)
            ->where('status', TeamMemberStatus::Active)
            ->with('owner')
            ->first();

        if ($membership !== null && self::seats($membership->owner) < 2) {
            $membership = null;
        }

        return self::$memberships[$user->id] = $membership;
    }
}
