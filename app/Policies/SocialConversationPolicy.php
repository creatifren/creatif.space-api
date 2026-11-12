<?php

namespace App\Policies;

use App\Models\SocialConversation;
use App\Models\User;
use App\Support\Inbox;
use Illuminate\Auth\Access\Response;

/**
 * A thread is seen by the account's owner and their active team; a
 * viewer seat reads but does not reply.
 */
class SocialConversationPolicy
{
    public function view(User $user, SocialConversation $conversation): bool|Response
    {
        // A disconnected channel's Engage data is gone for everyone (P1).
        if (! Inbox::onLiveChannel($conversation->account)) {
            return Response::denyAsNotFound();
        }

        return Inbox::owns($user, $conversation->user_id);
    }

    public function reply(User $user, SocialConversation $conversation): bool|Response
    {
        if (! Inbox::onLiveChannel($conversation->account)) {
            return Response::denyAsNotFound();
        }

        return Inbox::owns($user, $conversation->user_id) && Inbox::canWrite($user);
    }
}
