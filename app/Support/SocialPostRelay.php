<?php

namespace App\Support;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\PostForMeService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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
     * Send a held post and record who said yes — the one path for the
     * owner's approve, a client's decision, and both Approve-alls.
     *
     * Locked per post and re-checked inside the lock: two yeses arriving
     * together (a double tap, the client and the owner at once, a batch
     * overlapping a single decision) must not send — and charge — twice.
     * False means someone else decided first; that is not an error.
     *
     * @param  list<SocialPostApproval>  $from  the states this caller may approve out of
     *
     * @throws ConnectionException
     * @throws ValidationException when the Credits do not cover it
     */
    public function approve(SocialPost $post, string $by, array $from): bool
    {
        return Cache::lock("social-post:{$post->id}:approve", 60)->block(10, function () use ($post, $by, $from): bool {
            // Fresh state, not whatever this caller loaded earlier; `send`
            // reads the owner for the Credits check.
            $post->refresh()->loadMissing('user');

            if ($post->status !== SocialPostStatus::Scheduled || ! in_array($post->approval, $from, true)) {
                return false;
            }

            // Provider first: a failed send leaves the post held, not half-approved.
            $this->send($post);
            $post->update([
                'approval' => SocialPostApproval::Approved,
                'approval_by' => $by,
                'approval_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * Credits a set of posts needs to go out — checked once, up front, by
     * an Approve-all, so a short balance refuses the batch before a single
     * post reaches the provider.
     *
     * @param  iterable<SocialPost>  $posts
     */
    public static function need(iterable $posts): int
    {
        $destinations = 0;
        foreach ($posts as $post) {
            $destinations += $post->targets()->count();
        }

        return $destinations * Credits::PER_SEND;
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

    /**
     * One destination's outcome, from the webhook or from `reconcile`.
     * Redeliveries overwrite the same values — idempotent by construction.
     *
     * @param  array<string, mixed>  $data  a Post for Me post result
     */
    public static function applyResult(SocialPost $post, SocialAccount $account, array $data): void
    {
        $target = $post->targets()->where('social_account_id', $account->id)->first();

        if ($target === null) {
            return;
        }

        DB::transaction(function () use ($post, $target, $data): void {
            $success = (bool) ($data['success'] ?? false);

            // The vendor refused it: nothing left our hands, so its 3 Credits
            // come back — once, on the first failed result for this target.
            if (! $success && $target->status === SocialPostTargetStatus::Pending) {
                Credits::returnSend($post, 1, 'Not charged · '.Str::limit(
                    is_string($data['error'] ?? null) ? $data['error'] : 'the platform refused it', 120,
                ));
            }

            $target->update($success
                ? [
                    'status' => SocialPostTargetStatus::Published,
                    'fail_reason' => null,
                    'platform_url' => $data['platform_data']['url']
                        ?? $data['details']['url']
                        ?? null,
                    'provider_result_id' => $data['id'] ?? null,
                ]
                : [
                    'status' => SocialPostTargetStatus::Failed,
                    'fail_reason' => is_string($data['error'] ?? null)
                        ? $data['error']
                        : json_encode($data['error'] ?? 'Unknown error'),
                    'provider_result_id' => $data['id'] ?? null,
                ]);

            $post->recomputeFromTargets();
        });
    }

    /**
     * The safety net for a missed result webhook: posts whose slot has
     * passed but still read Scheduled ask the provider what happened. Without
     * it a post that went out stays "Scheduled" forever. A provider hiccup
     * leaves the rows as they were — the next list tries again.
     */
    public function reconcile(User $user): void
    {
        $overdue = $user->socialPosts()
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNotNull('provider_post_id')
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->with('targets.account')
            ->limit(50)
            ->get();

        if ($overdue->isEmpty()) {
            return;
        }

        try {
            $results = $this->postForMe->resultsFor($overdue->pluck('provider_post_id')->all());
        } catch (Throwable) {
            return;
        }

        $byProviderId = $overdue->keyBy('provider_post_id');

        foreach ($results as $result) {
            $post = $byProviderId->get((string) ($result['post_id'] ?? ''));
            $account = $post?->targets
                ->first(fn ($t) => $t->account->provider_account_id === (string) ($result['social_account_id'] ?? ''))
                ?->account;

            if ($post !== null && $account !== null) {
                self::applyResult($post, $account, $result);
            }
        }
    }
}
