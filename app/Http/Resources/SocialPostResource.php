<?php

namespace App\Http\Resources;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\PostDelivery;
use App\Support\PublishFailure;
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
            // Where delivery stands, one definition with ?status= and meta.counts
            // (PostDelivery): state published | partial | failed | pending and the
            // destination counts. Null for a draft or a cancelled post.
            'delivery' => $this->whenLoaded('targets', fn () => PostDelivery::summary($this->resource)),
            'approval' => $this->approval,
            'approval_by' => $this->approval_by,
            'approval_at' => $this->approval_at,
            // A draft the owner shows the client on a Content Plan.
            'for_review' => ($this->resource->getAttributes()['review_requested_at'] ?? null) !== null,
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
                    // This channel's own caption (captions_by_channel); null = the post's caption.
                    'caption' => is_array($target->configuration) && isset($target->configuration['caption'])
                        ? (string) $target->configuration['caption']
                        : null,
                    'status' => $target->status,
                    'fail_reason' => $target->fail_reason,
                    // PublishFailure reason code (needs_video, missing_board, …):
                    // one of PublishFailure::codes(); null when nothing went wrong.
                    'fail_code' => $target->fail_code,
                    // Its coarse group (auth, rate_limited, media, missing_field,
                    // content, duplicate, platform_unavailable, demo, unknown, other).
                    'error_code' => PublishFailure::coarse($target->fail_code),
                    // The network's own words, tidied (machine tags out, capped at
                    // 200): a detail line under fail_reason, never the headline.
                    'provider_message' => $target->fail_code !== null ? PublishFailure::detail($target->provider_error) : null,
                    // Waiting and sending again can fix it (or is already doing so).
                    'retryable' => $target->fail_code !== null && PublishFailure::retryable($target->fail_code),
                    // The account's sign-in is no longer accepted: reconnect it.
                    'reconnect_required' => in_array($target->fail_code, PublishFailure::RECONNECT, true),
                    // Waiting out a platform limit: still pending, retried then.
                    'retry_at' => $target->retry_at?->toIso8601String(),
                    // What a waiting destination is doing, in words (null unless it waits for a retry).
                    'retry_reason' => $target->retry_at !== null && $target->fail_code !== null
                        ? PublishFailure::waitingReason($target->fail_code, $target->account->platform->value, $target->retry_at)
                        : null,
                    'platform_url' => $target->platform_url,
                ],
            )->all()),
        ];
    }
}
