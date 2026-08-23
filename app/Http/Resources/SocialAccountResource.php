<?php

namespace App\Http\Resources;

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
            'status' => $this->status,
            'connected_at' => $this->connected_at,
        ];
    }
}
