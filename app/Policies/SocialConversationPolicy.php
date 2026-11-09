<?php

namespace App\Policies;

use App\Models\SocialConversation;
use App\Models\User;
use App\Support\Inbox;

/**
 * A thread is seen by the account's owner and their active team; a
 * viewer seat reads but does not reply.
 */
class SocialConversationPolicy
{
    public function view(User $user, SocialConversation $conversation): bool
    {
        return Inbox::owns($user, $conversation->user_id);
    }

    public function reply(User $user, SocialConversation $conversation): bool
    {
        return Inbox::owns($user, $conversation->user_id) && Inbox::canWrite($user);
    }
}
