<?php

namespace App\Http\Resources;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialAccount
 */
class SocialAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'platform' => $this->platform,
            'username' => $this->username,
            'profile_photo_url' => $this->profile_photo_url,
            // Connected under another relay reads as disconnected, so the
            // composer, Channels and Engage stop offering it with no app
            // change; reconnect_required says why.
            'status' => $this->isLegacy() ? SocialAccountStatus::Disconnected : $this->status,
            'reconnect_required' => $this->isLegacy(),
            'connected_at' => $this->connected_at,
            'messaging_enabled' => $this->messagingEnabled(),
            'needs_reconnect_for_messages' => $this->needsReconnectForMessages(),
        ];
    }
}
