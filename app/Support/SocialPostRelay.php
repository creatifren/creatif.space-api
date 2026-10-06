<?php

namespace App\Support;

use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialPost;
use App\Services\PostForMeService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

/**
 * A stored post as the provider's create/replace body. Post for Me's PUT is
 * a full replace, and a held post is sent only once approved — both rebuild
 * the body from the row, so destinations, media and per-account captions
 * are whatever was saved, never a guess.
 */
class SocialPostRelay
{
    /** Our post type → Post for Me's placement value. */
    private const PLACEMENT = ['reels' => 'reels', 'story' => 'stories'];

    /** The platforms Post for Me takes a placement for. */
    private const PLACED = ['facebook', 'instagram', 'threads'];

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
            ->map(fn ($t) => self::configuration(
                $t->account->provider_account_id,
                $t->account->platform->value,
                $t->configuration,
                $post->type ?? 'post',
            ))
            ->filter()
            ->values();

        if ($configurations->isNotEmpty()) {
            $payload['account_configurations'] = $configurations->all();
        }

        return $payload;
    }

    /**
     * One account's configuration entry, or null when it needs none. Reels
     * and Story are a placement on the platforms that have one; elsewhere
     * the post goes out as an ordinary post.
     *
     * @param  array<string, mixed>|null  $own  the per-account caption override
     * @return array{social_account_id: string, configuration: array<string, mixed>}|null
     */
    public static function configuration(string $providerId, string $platform, ?array $own, string $type): ?array
    {
        $config = $own ?? [];
        $placement = self::PLACEMENT[$type] ?? null;

        if ($placement !== null && in_array($platform, self::PLACED, true)) {
            $config['placement'] = $placement;
        }

        return $config === [] ? null : ['social_account_id' => $providerId, 'configuration' => $config];
    }

    /**
     * Hand a held post to the provider now that it may go.
     *
     * @throws ConnectionException
     */
    public function send(SocialPost $post): void
    {
        $destinations = $post->targets()->count();

        // Refuse before the provider, not after: a short balance must not
        // leave a post live on the platform that was never paid for.
        $need = $destinations * Credits::PER_SEND;
        if (Credits::balance($post->user) < $need) {
            throw ValidationException::withMessages([
                'caption' => "This needs {$need} Credits and the balance has ".Credits::balance($post->user)
                    .' — top up, then try again.',
            ]);
        }

        $remote = $this->postForMe->createPost($this->payload($post));
        Credits::chargeSend($post, $destinations);

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
     * taken back, or its only account disconnected. The destinations that
     * had not gone out are paid back.
     *
     * @throws ConnectionException
     */
    public function recall(SocialPost $post, string $why): void
    {
        if ($post->provider_post_id === null) {
            return;
        }

        $this->postForMe->deletePost($post->provider_post_id);
        Credits::returnSend(
            $post,
            $post->targets()->where('status', SocialPostTargetStatus::Pending)->count(),
            $why,
        );
        $post->update(['provider_post_id' => null]);
    }
}
