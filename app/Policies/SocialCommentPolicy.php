<?php

namespace App\Policies;

use App\Models\SocialComment;
use App\Models\User;
use App\Support\Inbox;

/** The same rule as threads: owner and team see, writers reply. */
class SocialCommentPolicy
{
    public function view(User $user, SocialComment $comment): bool
    {
        return Inbox::owns($user, $comment->user_id);
    }

    public function reply(User $user, SocialComment $comment): bool
    {
        return Inbox::owns($user, $comment->user_id) && Inbox::canWrite($user);
    }
}
