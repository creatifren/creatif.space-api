<?php

namespace App\Support;

use App\Enums\TeamMemberStatus;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Turning an invitation into a membership.
 *
 * Called from the Google callback for every sign-in, not only new
 * accounts: somebody invited today may already have had an account for a
 * year, and their first login after the invite is when it should take.
 */
final class TeamInvite
{
    /**
     * Claim any seat waiting on this person's email address.
     *
     * Matching on email rather than a token means the invite link is a
     * convenience and Google is what proves who they are — a link that
     * leaks cannot be used by somebody with a different address.
     */
    public static function claim(User $user): void
    {
        TeamMember::query()
            ->where('email', $user->email)
            ->where('status', TeamMemberStatus::Invited)
            // No joining your own team.
            ->where('owner_id', '!=', $user->id)
            ->get()
            ->each(function (TeamMember $member) use ($user): void {
                $member->forceFill([
                    'member_id' => $user->id,
                    'status' => TeamMemberStatus::Active,
                    'joined_at' => now(),
                ])->save();
            });
    }
}
