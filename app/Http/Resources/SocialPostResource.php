<?php

namespace App\Http\Resources;

use App\Models\SocialPost;
use App\Support\Media\MediaLinks;
use App\Models\SocialPostTarget;
use App\Support\ClientBadges;
use App\Support\Media\PostMediaVariants;
use App\Support\PostDelivery;
use App\Support\PostSpaces;
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
            // v10.2: posted as the first comment on IG / FB / LinkedIn once live.
            'first_comment' => $this->first_comment,
            'type' => $this->type,
            'labels' => $this->labels ?? [],
            'media' => MediaLinks::freshMedia($this->media),
            // Small copies per media item (same order; null = not a library
            // file or none made yet): the dashboard draws these, not the original.
            'media_variants' => PostMediaVariants::for($request, $this->resource),
            'scheduled_at' => $this->scheduled_at,
            'status' => $this->status,
            'fail_reason' => $this->fail_reason,
            // Where delivery stands, one definition with ?status= and meta.counts
            // (PostDelivery): state published | partial | failed | pending and the
            // destination counts. Null for a draft or a cancelled post.
            'delivery' => $this->whenLoaded('targets', fn () => PostDelivery::summary($this->resource)),
            // v7 client labels: every client on any of the post's accounts.
            'clients' => $this->whenLoaded('targets', fn () => ClientBadges::forPost($this->resource)),
            // v7 "Also add to": the Galleries / Blogs it goes into when published.
            'also_add_to' => PostSpaces::for($this->id),
            'approval' => $this->approval,
            // Sample data (SampleData): the app badges it "Sample".
            'sample' => (bool) $this->is_demo,
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
                    // v10.2 First comment on this account: posted | failed | skipped | pending | null (none asked).
                    'first_comment' => self::firstComment($this->resource, $target),
                    'fail_reason' => $target->fail_reason,
                    // PublishFailure reason code (needs_video, missing_board, …):
                    // one of PublishFailure::codes(); null when nothing went wrong.
                    'fail_code' => $target->fail_code,
                    // Its coarse group (auth, rate_limited, media, missing_field,
                    // content, duplicate, platform_unavailable, demo, unknown, other).
                    'error_code' => PublishFailure::coarse($target->fail_code),
                    // The network's own words, tidied (machine tags out, capped at
                    // 200), only for other / media_spec / content_rejected /
                    // content_too_long (already quoted in fail_reason); null for
                    // every other code: the raw text stays server-side.
                    'provider_message' => PublishFailure::shownDetail($target->fail_code, $target->provider_error),
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

    /** Where this account's first comment stands; null when the post has none or the network takes none. */
    private static function firstComment(SocialPost $post, SocialPostTarget $target): ?string
    {
        if (trim((string) $post->first_comment) === '' || ! in_array($target->account?->platform?->value, \App\Jobs\PostFirstComment::NETWORKS, true)) {
            return null;
        }

        return match (true) {
            $target->first_comment_at !== null => 'posted',
            $target->first_comment_error === 'not_supported' => 'skipped',
            $target->first_comment_error !== null => 'failed',
            default => 'pending',
        };
    }
}
