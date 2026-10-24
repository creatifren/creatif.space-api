<?php

namespace App\Http\Resources;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialPost
 */
class SocialPostResource extends JsonResource
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
            'caption' => $this->caption,
            'type' => $this->type,
            'labels' => $this->labels ?? [],
            'media' => $this->media,
            'scheduled_at' => $this->scheduled_at,
            'status' => $this->status,
            'fail_reason' => $this->fail_reason,
            'approval' => $this->approval,
            'approval_by' => $this->approval_by,
            'approval_at' => $this->approval_at,
            // "Created by" in the post's history (RF-040): the account that
            // made it, not whoever is reading.
            'author' => $this->whenLoaded('user', fn () => $this->user->name),
            'comments' => $this->whenLoaded('comments', fn () => $this->comments
                ->map(fn ($c) => $c->toPublicArray())->all()),
            'created_at' => $this->created_at,
            // Targets never appear outside their post, so they are mapped
            // inline rather than through a resource of their own.
            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(
                fn (SocialPostTarget $target) => [
                    'account' => [
                        'id' => $target->account->ulid,
                        'platform' => $target->account->platform,
                        'username' => $target->account->username,
                    ],
                    'status' => $target->status,
                    'fail_reason' => $target->fail_reason,
                    'platform_url' => $target->platform_url,
                ],
            )->all()),
        ];
    }
}
