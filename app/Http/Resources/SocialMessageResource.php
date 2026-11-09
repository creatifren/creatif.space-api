<?php

namespace App\Http\Resources;

use App\Models\SocialMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialMessage
 */
class SocialMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'direction' => $this->direction,
            'content' => $this->content,
            'media_urls' => $this->media_urls ?? [],
            'status' => $this->status,
            'error' => $this->error,
            'source' => $this->source,
            'scheduled_at' => $this->scheduled_at,
            'sent_at' => $this->sent_at,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
