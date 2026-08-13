<?php

namespace App\Support;

use App\Models\Affiliate;
use App\Models\Referral;
use App\Models\User;

/**
 * Attaching a new account to whoever sent them.
 *
 * Called from the Google callback, and only on the branch that just
 * created the account: a referral belongs to a *new* customer, never to
 * somebody signing back into an account they already had.
 */
final class ReferralAttribution
{
    /**
     * Record the signup against a referral code. Every failure here is a
     * no-op rather than an error — this runs inside a login, and a bad
     * code in a URL must never be the reason somebody cannot sign in.
     */
    public static function attach(User $user, string $code): void
    {
        $affiliate = Affiliate::query()->where('code', $code)->first();

        if ($affiliate === null || ! $affiliate->isApproved()) {
            return;
        }

        // No referring yourself. Enforced in the application because a
        // cross-table check constraint is not portable, and covered by a
        // test so it stays enforced.
        if ($affiliate->user_id === $user->id) {
            return;
        }

        // referred_user_id is unique, so a second attempt updates rather
        // than throwing a 500 in the middle of a login.
        Referral::query()->updateOrCreate(
            ['referred_user_id' => $user->id],
            [
                'affiliate_id' => $affiliate->id,
                'signed_up_at' => now(),
                'cookie_expires_at' => now()->addDays(Affiliate::COOKIE_DAYS),
            ],
        );
    }

    /**
     * A click, before anybody has signed up. Kept as its own row with a
     * null user — that is what makes the funnel's first number real rather
     * than reconstructed from signups.
     */
    public static function click(string $code): void
    {
        $affiliate = Affiliate::query()->where('code', $code)->first();

        if ($affiliate === null || ! $affiliate->isApproved()) {
            return;
        }

        Referral::query()->create([
            'affiliate_id' => $affiliate->id,
            'referred_user_id' => null,
            'clicked_at' => now(),
            'cookie_expires_at' => now()->addDays(Affiliate::COOKIE_DAYS),
        ]);
    }
}
