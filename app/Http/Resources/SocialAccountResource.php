<?php

namespace App\Http\Resources;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Support\ChannelHealth;
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
            // Also true when the last check or a publish found its sign-in no
            // longer accepted (ChannelHealth::RECONNECT_REASONS): status stays
            // connected, but posting to it fails until it is reconnected.
            'reconnect_required' => ChannelHealth::reconnectRequired($this->resource),
            'connected_at' => $this->connected_at,
            'messaging_enabled' => $this->messagingEnabled(),
            'needs_reconnect_for_messages' => $this->needsReconnectForMessages(),
            // Cached, refreshed in the background at most every 6 h; null
            // when the network does not report it (or not fetched yet).
            'followers_count' => $this->followers_count,
            // The last live check (ChannelHealth): ok | failed (null: not checked yet),
            // why it failed, when. A channel that cannot be posted to is
            // failed (needs_attention) whatever the last check said.
            ...ChannelHealth::present($this->resource),
        ];
    }
}
