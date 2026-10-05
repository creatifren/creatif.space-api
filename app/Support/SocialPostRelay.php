<?php

namespace App\Support;

use App\Enums\SocialPostStatus;
use App\Models\SocialPost;
use App\Services\PostForMeService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;

/**
 * A stored post as the provider's create/replace body. Post for Me's PUT is
 * a full replace, and a held post is sent only once approved — both rebuild
 * the body from the row, so destinations, media and per-account captions
 * are whatever was saved, never a guess.
 */
class SocialPostRelay
{
    public function __construct(private PostForMeService $postForMe) {}

    /**
     * The body from the row; `$caption`/`$at` override it for an edit that
     * is sent before it is saved.
     *
     * @return array<string, mixed>
     */
    public function payload(SocialPost $post, ?string $caption = null, ?CarbonInterface $at = null): array
    {
        $targets = $post->targets()->with('account')->get();
        $at ??= $post->scheduled_at;

        $payload = [
            'caption' => $caption ?? $post->caption,
            'social_accounts' => $targets->pluck('account.provider_account_id')->all(),
            'external_id' => $post->ulid,
        ];

        // A slot that passed while the post waited goes out now.
        if ($at !== null && $at->isFuture()) {
            $payload['scheduled_at'] = $at->toIso8601String();
        }

        if ($post->media !== null) {
            $payload['media'] = $post->media;
        }

        $configurations = $targets
            ->filter(fn ($t) => $t->configuration !== null)
            ->map(fn ($t) => [
                'social_account_id' => $t->account->provider_account_id,
                'configuration' => $t->configuration,
            ])
            ->values();

        if ($configurations->isNotEmpty()) {
            $payload['account_configurations'] = $configurations->all();
        }

        return $payload;
    }

    /**
     * Hand a held post to the provider now that it may go.
     *
     * @throws ConnectionException
     */
    public function send(SocialPost $post): void
    {
        $remote = $this->postForMe->createPost($this->payload($post));

        $post->update([
            'provider_post_id' => (string) $remote['id'],
            'scheduled_at' => $post->scheduled_at?->isFuture() ? $post->scheduled_at : null,
            'status' => ($remote['status'] ?? null) === 'processed'
                ? SocialPostStatus::Published
                : SocialPostStatus::Scheduled,
        ]);
    }

    /**
     * Pull a sent-but-not-out post back so it is held again — approval
     * taken back after it was given.
     *
     * @throws ConnectionException
     */
    public function recall(SocialPost $post): void
    {
        if ($post->provider_post_id !== null) {
            $this->postForMe->deletePost($post->provider_post_id);
            $post->update(['provider_post_id' => null]);
        }
    }
}
