<?php

namespace App\Policies;

use App\Models\SocialComment;
use App\Models\User;
use App\Support\Inbox;
use Illuminate\Auth\Access\Response;

/** The same rule as threads: owner and team see, writers reply. */
class SocialCommentPolicy
{
    public function view(User $user, SocialComment $comment): bool|Response
    {
        // A disconnected channel's Engage data is gone for everyone (P1).
        if (! Inbox::onLiveChannel($comment->account)) {
            return Response::denyAsNotFound();
        }

        return Inbox::owns($user, $comment->user_id);
    }

    public function reply(User $user, SocialComment $comment): bool|Response
    {
        if (! Inbox::onLiveChannel($comment->account)) {
            return Response::denyAsNotFound();
        }

        return Inbox::owns($user, $comment->user_id) && Inbox::canWrite($user);
    }
}
