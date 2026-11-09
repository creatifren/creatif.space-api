<?php

namespace App\Http\Resources;

use App\Models\SocialConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialConversation
 */
class SocialConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $until = $this->canReplyUntil();

        return [
            'id' => $this->ulid,
            'account' => [
                'id' => $this->account->ulid,
                'platform' => $this->account->platform,
                'username' => $this->account->username,
                'profile_photo_url' => $this->account->profile_photo_url,
            ],
            'network' => $this->network,
            'contact' => [
                'id' => $this->contact_id,
                'username' => $this->contact_username,
                'name' => $this->contact_name,
                'avatar_url' => $this->contact_avatar_url,
            ],
            'last_message_preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at,
            'last_inbound_at' => $this->last_inbound_at,
            // Instagram's 24h rule: a free-form reply only until then.
            'can_reply_until' => $until,
            'can_reply' => $until !== null && $until->isFuture(),
            'unread_count' => $this->unread_count,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
