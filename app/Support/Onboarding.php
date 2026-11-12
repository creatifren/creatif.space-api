<?php

namespace App\Support;

use App\Enums\NotificationType;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\ConnectChannelNudge;
use App\Notifications\WelcomeEmail;
use App\Services\Analytics;
use Carbon\CarbonImmutable;

/**
 * The owner's two onboarding emails, each at most once per account ever.
 *
 *  - Welcome (WelcomeEmail): when a creator's account is ready — a new
 *    Google account, or an email signup once its address is verified. Not
 *    for someone who signed in only to answer an approval or a content plan
 *    (they came for /a/… or /plan/…, or by the approver email link): they
 *    are a client, not a creator. Account email, so it is always sent.
 *  - Connect nudge (ConnectChannelNudge): 24 hours after the welcome went
 *    out, if still no channel is connected. Respects Settings →
 *    Notifications → Product news (email). Never for internal accounts or
 *    accounts that are not active.
 *
 * "Once" is a column set in one conditional UPDATE before the email is
 * queued (users.welcome_sent_at / connect_nudge_sent_at), so a retried
 * request or an overlapping scheduler run can never send it twice.
 */
final class Onboarding
{
    /** The nudge goes out this long after signing up… */
    public const NUDGE_AFTER_HOURS = 24;

    /** …and not for accounts older than this (no backlog of stale nudges after a deploy or an outage). */
    public const NUDGE_UNTIL_HOURS = 72;

    /** A sign-in that was on its way to one of these was a client answering, not a creator signing up. */
    private const CLIENT_PATHS = ['/a/', '/plan/'];

    public static function isClientLanding(mixed $next): bool
    {
        if (! is_string($next) || $next === '') {
            return false;
        }

        foreach (self::CLIENT_PATHS as $prefix) {
            if (str_starts_with($next, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Send the welcome email unless this account already had it. */
    public static function welcome(User $user): void
    {
        if (! self::claim($user, 'welcome_sent_at')) {
            return;
        }

        $user->notify(new WelcomeEmail);
    }

    /**
     * The nudge for one account, if it is due. Returns whether it was sent.
     */
    public static function nudge(User $user, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        // A model just made in memory (create()) does not carry the columns
        // left to their database defaults, status among them: read the row.
        if (! array_key_exists('status', $user->getAttributes())) {
            $user->refresh();
        }
        $attributes = $user->getAttributes();

        if (($attributes['connect_nudge_sent_at'] ?? null) !== null
            || ($attributes['welcome_sent_at'] ?? null) === null
            || $user->status !== UserStatus::Active
            || Analytics::isInternal($user)
            || $user->socialAccounts()->exists()
            || ! $user->wants(NotificationType::ProductNews, 'email')) {
            return false;
        }

        $created = $user->created_at;
        if ($created === null
            || $created->greaterThan($now->subHours(self::NUDGE_AFTER_HOURS))
            || $created->lessThan($now->subHours(self::NUDGE_UNTIL_HOURS))) {
            return false;
        }

        if (! self::claim($user, 'connect_nudge_sent_at')) {
            return false;
        }

        $user->notify(new ConnectChannelNudge);

        return true;
    }

    /** Mark $column for $user if it is still empty; true only for the one call that marked it. */
    private static function claim(User $user, string $column): bool
    {
        $now = now();
        $claimed = User::query()->whereKey($user->getKey())->whereNull($column)->update([$column => $now]) === 1;
        if ($claimed) {
            $user->forceFill([$column => $now])->syncOriginalAttribute($column);
        }

        return $claimed;
    }
}
