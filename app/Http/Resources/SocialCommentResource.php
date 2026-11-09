<?php

namespace App\Http\Resources;

use App\Models\SocialComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialComment
 */
class SocialCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $until = $this->canPrivateReplyUntil();

        return [
            'id' => $this->ulid,
            'account' => [
                'id' => $this->account->ulid,
                'platform' => $this->account->platform,
                'username' => $this->account->username,
            ],
            'post_id' => $this->post?->ulid,
            'provider_post_id' => $this->provider_post_id,
            'platform_post_id' => $this->platform_post_id,
            'comment_id' => $this->comment_id,
            'parent_comment_id' => $this->parent_comment_id,
            'author' => [
                'id' => $this->author_id,
                'username' => $this->author_username,
            ],
            'text' => $this->text,
            'network' => $this->network,
            'commented_at' => $this->commented_at,
            'read_at' => $this->read_at,
            'replied_at' => $this->replied_at,
            'private_replied_at' => $this->private_replied_at,
            // Instagram: one private DM reply per comment, within 7 days.
            'can_private_reply_until' => $until,
            'can_private_reply' => $until !== null && $until->isFuture(),
            'private_reply_conversation_id' => $this->privateReplyConversation?->ulid,
        ];
    }
}
