<?php

namespace App\Support;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A stored post as the provider's create/replace body (SocialProvider's
 * shape; each provider translates). Replaces are full replaces, and a held post is sent only once approved — both rebuild
 * the body from the row, so destinations, media and per-account captions
 * are whatever was saved, never a guess.
 */
class SocialPostRelay
{
    /** Our post type → the placement value in our post body. */
    private const PLACEMENT = ['reels' => 'reels', 'story' => 'stories'];

    /** The platforms that take a placement. */
    private const PLACED = ['facebook', 'instagram', 'threads'];

    public function __construct(private SocialProviders $providers) {}

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
        // Refuse before the provider, not after: a short balance must not
        // leave a post live on the platform that was never paid for.
        $need = Credits::sendPrice($post->user, self::destinations($post));
        if (Credits::balance($post->user) < $need) {
            throw ValidationException::withMessages([
                'caption' => "This needs {$need} Credits and the balance has ".Credits::balance($post->user)
                    .' — top up, then try again.',
            ]);
        }

        // Paid before it reaches the provider, under the owner's lock, so a
        // teammate spending the same wallet cannot let it go out unpaid.
        Credits::chargeSend($post);

        try {
            $remote = $this->providers->for($post)->createPost($this->payload($post));
        } catch (Throwable $e) {
            Credits::returnSend($post, $post->targets()->get(), 'Not sent · the provider refused it');

            throw $e;
        }

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
     * post reaches the provider. What the Monthly Credits still cover comes from them.
     *
     * @param  iterable<SocialPost>  $posts
     */
    public static function need(User $owner, iterable $posts): int
    {
        $destinations = [];
        foreach ($posts as $post) {
            array_push($destinations, ...self::destinations($post));
        }

        return Credits::sendPrice($owner, $destinations);
    }

    /**
     * Each destination as [platform, caption] — what Credits prices: X
     * costs more when its caption has a link.
     *
     * @return list<array{0: SocialPlatform, 1: string|null}>
     */
    private static function destinations(SocialPost $post): array
    {
        return $post->targets()->with('account')->orderBy('id')->get()
            ->map(fn ($t) => [$t->account->platform, $t->configuration['caption'] ?? $post->caption])
            ->values()
            ->all();
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

        $this->providers->for($post)->deletePost($post->provider_post_id);
        Credits::returnSend(
            $post,
            $post->targets()->where('status', SocialPostTargetStatus::Pending)->get(),
            $why,
        );
        $post->update(['provider_post_id' => null]);
    }

    /**
     * One destination's outcome, from the webhook or from `reconcile`.
     * Redeliveries overwrite the same values — idempotent by construction.
     * The analytics event goes out only on a change of state, read under a
     * row lock, so a redelivered result (or reconcile racing the webhook)
     * is not a second publish.
     *
     * @param  array<string, mixed>  $data  a post result in SocialProvider's shape
     */
    public static function applyResult(SocialPost $post, SocialAccount $account, array $data): void
    {
        $target = $post->targets()->where('social_account_id', $account->id)->first();

        if ($target === null) {
            return;
        }

        DB::transaction(function () use ($post, $target, $data): void {
            $target = SocialPostTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $before = $target->status;
            $success = (bool) ($data['success'] ?? false);

            // The vendor refused it: nothing left our hands, so its publish
            // comes back — once, on the first failed result for this target.
            if (! $success && $target->status === SocialPostTargetStatus::Pending) {
                Credits::returnSend($post, [$target], 'Not charged · '.Str::limit(
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

            ProductEvents::publishResult($post, $target, $before, is_string($data['error'] ?? null) ? $data['error'] : null);
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

        // Each relay is asked about its own posts; one being down leaves
        // the other's to settle.
        foreach ($overdue->groupBy('provider') as $provider => $posts) {
            try {
                $results = $this->providers->named((string) $provider)
                    ->resultsFor($posts->pluck('provider_post_id')->values()->all());
            } catch (Throwable) {
                continue;
            }

            $byProviderId = $posts->keyBy('provider_post_id');

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
}
