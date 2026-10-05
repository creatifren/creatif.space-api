<?php

namespace App\Enums;

/**
 * Where a post stands with its approver. Null on the post = approval off.
 * Anything but Approved is held: the post has not reached the provider.
 */
enum SocialPostApproval: string
{
    /** Waiting for the editor's own yes. */
    case Awaiting = 'awaiting';
    /** Waiting for a client on an approval link. */
    case Client = 'client';
    case Approved = 'approved';
    /** The client asked for changes — a comment says what. */
    case Revision = 'revision';

    public function held(): bool
    {
        return $this !== self::Approved;
    }
}
