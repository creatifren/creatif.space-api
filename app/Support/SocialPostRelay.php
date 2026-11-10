<?php

namespace App\Support;

use App\Casts\UtcDateTime;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Exceptions\AccountReconnectRequired;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PublishPreflightFailed;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
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
     * @throws AccountReconnectRequired when a destination is not usable
     */
    public function send(SocialPost $post): void
    {
        // A destination left under another relay cannot be sent through
        // this one: named, before anything is charged.
        self::ensureUsable($post);

        // What a network would refuse is named now, not after the charge.
        $this->preflight($post);

        // Refuse before the provider, not after: a short balance must not
        // leave a post live on the platform that was never paid for.
        $need = Credits::sendPrice($post->user, self::destinations($post));
        if (Credits::balance($post->user) < $need) {
            // What Top-up must cover plus what Monthly does: the full price.
            throw NotEnoughCredits::for($post->user, $need + Credits::monthlyBalance($post->user));
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
     * Check a stored post against each network's requirements, filling a
     * Pinterest board in first where the account has exactly one.
     *
     * @throws PublishPreflightFailed
     */
    public function preflight(SocialPost $post): void
    {
        $targets = $post->targets()->with('account')->orderBy('id')->get();
        $accounts = $targets->pluck('account')->filter();

        $options = [];
        $captions = [];
        foreach ($targets as $t) {
            $options[$t->account->ulid] = (array) ($t->configuration ?? []);
            if (isset($t->configuration['caption'])) {
                $captions[$t->account->ulid] = (string) $t->configuration['caption'];
            }
        }

        $resolved = $this->withPinterestBoards($accounts, $options);
        foreach ($targets as $t) {
            $board = $resolved[$t->account->ulid]['board_id'] ?? null;
            if ($board !== null && ($options[$t->account->ulid]['board_id'] ?? null) !== $board) {
                $t->update(['configuration' => [...(array) ($t->configuration ?? []), 'board_id' => $board]]);
            }
        }

        PublishPreflight::check($accounts, (string) $post->caption, $captions, $post->media, $resolved);
    }

    /**
     * A Pinterest account with no board chosen gets its only board, when it
     * has exactly one. With several, the person picks (preflight says so);
     * a relay that cannot list boards leaves it to preflight too.
     *
     * @param  iterable<SocialAccount>  $accounts
     * @param  array<string, array<string, mixed>>  $options  by account ulid
     * @return array<string, array<string, mixed>>
     */
    public function withPinterestBoards(iterable $accounts, array $options): array
    {
        foreach ($accounts as $account) {
            if ($account->platform !== SocialPlatform::Pinterest || ! empty($options[$account->ulid]['board_id'])) {
                continue;
            }

            try {
                $boards = $this->providers->named((string) $account->provider)->pinterestBoards($account->provider_account_id);
            } catch (Throwable) {
                continue;
            }

            if (count($boards) === 1) {
                $options[$account->ulid] = [...($options[$account->ulid] ?? []), 'board_id' => $boards[0]['id']];
            }
        }

        return $options;
    }

    /**
     * Refuse when a destination is not usable (Connected, under the current
     * relay) — the one gate for send, approve and any edit that reaches the
     * provider.
     *
     * @throws AccountReconnectRequired
     */
    public static function ensureUsable(SocialPost $post): void
    {
        AccountReconnectRequired::unlessUsable(
            $post->targets()->with('account')->orderBy('id')->get()->pluck('account')->filter(),
        );
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
     * @throws AccountReconnectRequired when a destination is not usable
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
     * The status is always brought up to date, but the analytics event is
     * held back for a post of a relay that is no longer current (the Post
     * for Me backlog after the Outstand switch, settled by reconcile) and
     * for a result stamped more than a day before the target was sent.
     *
     * @param  array<string, mixed>  $data  a post result in SocialProvider's shape
     * @param  'webhook'|'reconcile'|'job'  $source
     */
    public static function applyResult(SocialPost $post, SocialAccount $account, array $data, string $source = 'job'): void
    {
        $target = $post->targets()->where('social_account_id', $account->id)->first();

        if ($target === null) {
            return;
        }

        DB::transaction(function () use ($post, $account, $target, $data, $source): void {
            $target = SocialPostTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            // Once a destination was retried, only the retry's result speaks
            // for it: a redelivered result of the earlier attempt is history.
            $resultPost = (string) ($data['post_id'] ?? '');
            if ($target->retry_provider_post_id !== null && $resultPost !== '' && $resultPost !== $target->retry_provider_post_id) {
                return;
            }

            $before = $target->status;
            $success = (bool) ($data['success'] ?? false);
            $raw = self::rawError($data['error'] ?? null);
            $network = $account->platform->value;

            if ($success) {
                $target->update([
                    'status' => SocialPostTargetStatus::Published,
                    'fail_reason' => null,
                    'fail_code' => null,
                    'retry_at' => null,
                    'platform_url' => ProviderFields::url($data['platform_data']['url']
                        ?? $data['details']['url']
                        ?? null),
                    'provider_result_id' => $data['id'] ?? null,
                ]);
            } else {
                $failure = PublishFailure::classify($raw, $network);

                // A limit, not a verdict: try again later instead of failing.
                // Still Pending, still paid for, no failure event yet.
                if ($before === SocialPostTargetStatus::Pending && self::willRetry($post, $target, $failure['reason_code'])) {
                    $target->update([
                        'fail_code' => $failure['reason_code'],
                        'provider_error' => $raw,
                        'retry_at' => self::retryAt($failure['reason_code'], $target->attempts, $raw),
                        'provider_result_id' => $data['id'] ?? null,
                    ]);

                    return;
                }

                // The vendor refused it: nothing left our hands, so its publish
                // comes back — once, on the first failed result for this target.
                if ($before === SocialPostTargetStatus::Pending) {
                    Credits::returnSend($post, [$target], 'Not charged · '.Str::limit($failure['reason'], 120));
                }

                $target->update([
                    'status' => SocialPostTargetStatus::Failed,
                    'fail_reason' => $failure['reason'],
                    'fail_code' => $failure['reason_code'],
                    'provider_error' => $raw,
                    'retry_at' => null,
                    'provider_result_id' => $data['id'] ?? null,
                ]);
            }

            $post->recomputeFromTargets();

            if (self::countsAsNews($post, $target, $data)) {
                ProductEvents::publishResult($post, $target, $before, $raw, $source);
            }
        });
    }

    /** Most retries a destination gets after its first attempt. */
    public const MAX_RETRIES = 3;

    /** Minutes to wait before retry n (0-based), by reason. */
    private const BACKOFF = [
        'rate_limited' => [2, 10, 30],
        'platform_unavailable' => [2, 10, 30],
        'quota_exceeded' => [60, 180, 360],
    ];

    /**
     * Retries are for the current relay only: a result of a legacy relay
     * cannot be re-sent through it.
     */
    private static function willRetry(SocialPost $post, SocialPostTarget $target, string $reasonCode): bool
    {
        return PublishFailure::retryable($reasonCode)
            && $target->attempts < self::MAX_RETRIES
            && $post->provider === (string) config('services.social.provider');
    }

    /**
     * Backoff by attempt, or the platform's own retryAt when it names one
     * (YouTube's shared quota: "[youtube_upload_error:daily_limit_reached;
     * retryAt=2026-10-07T07:00:00.000Z]") — whichever is later.
     */
    public static function retryAt(string $reasonCode, int $attempts, ?string $raw): CarbonImmutable
    {
        $steps = self::BACKOFF[$reasonCode] ?? self::BACKOFF['rate_limited'];
        $at = CarbonImmutable::now()->addMinutes($steps[min($attempts, count($steps) - 1)]);

        if ($raw !== null && preg_match('/retryAt=([0-9T:\.\-]+Z)/', $raw, $m) === 1) {
            try {
                // In the app's zone: a datetime column stores wall-clock time.
                $named = CarbonImmutable::parse($m[1])->setTimezone((string) config('app.timezone'));
                if ($named->greaterThan($at) && $named->lessThan(CarbonImmutable::now()->addDays(2))) {
                    $at = $named;
                }
            } catch (Throwable) {
                // Unparseable: the backoff stands.
            }
        }

        return $at;
    }

    private static function rawError(mixed $error): ?string
    {
        return match (true) {
            is_string($error) && $error !== '' => $error,
            $error === null || $error === '' => null,
            default => (string) json_encode($error),
        };
    }

    /**
     * Send one destination again on its own — a rate-limited one whose
     * wait is over. A new provider post with just this account; its result
     * arrives like any other and is matched by retry_provider_post_id.
     *
     * @throws Throwable when the provider refuses the create
     */
    public function retryTarget(SocialPostTarget $target): void
    {
        $post = $target->post()->with('user')->firstOrFail();
        $account = $target->account()->firstOrFail();

        if (! $account->isUsable()) {
            self::applyResult($post, $account, [
                'success' => false,
                'error' => 'Account needs to reconnect',
                'post_id' => $target->retry_provider_post_id,
            ], 'job');

            return;
        }

        $payload = $this->payload($post);
        $payload['social_accounts'] = [$account->provider_account_id];
        $payload['external_id'] = $post->ulid.'-r'.$target->id.'-'.($target->attempts + 1);
        if (isset($payload['account_configurations'])) {
            $payload['account_configurations'] = array_values(array_filter(
                $payload['account_configurations'],
                fn ($c) => ($c['social_account_id'] ?? null) === $account->provider_account_id,
            ));
            if ($payload['account_configurations'] === []) {
                unset($payload['account_configurations']);
            }
        }

        $remote = $this->providers->for($post)->createPost($payload);

        $target->update([
            'attempts' => $target->attempts + 1,
            'retry_at' => null,
            'retry_provider_post_id' => (string) $remote['id'],
        ]);
    }

    /**
     * Whether a result is today's news for analytics: the post's relay is
     * the current one, and the result is not stamped more than a day
     * before the target was sent (a replayed old result).
     *
     * @param  array<string, mixed>  $data
     */
    private static function countsAsNews(SocialPost $post, SocialPostTarget $target, array $data): bool
    {
        if ($post->provider !== (string) config('services.social.provider')) {
            return false;
        }

        $stamp = $data['created_at'] ?? $data['published_at'] ?? $data['posted_at'] ?? null;
        if (! is_string($stamp) || $stamp === '') {
            return true;
        }

        try {
            $at = CarbonImmutable::parse($stamp);
        } catch (Throwable) {
            return true;
        }
        $sent = $target->created_at ?? $post->created_at;

        return $sent === null || $at->greaterThanOrEqualTo($sent->subDay());
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
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', UtcDateTime::bind(now())))
            ->with('targets.account')
            ->limit(50)
            ->get();

        if ($overdue->isEmpty()) {
            return;
        }

        // Each relay is asked about its own posts; one being down leaves
        // the other's to settle.
        foreach ($overdue->groupBy('provider') as $provider => $posts) {
            // A destination being retried has its own provider post.
            $byProviderId = $posts->keyBy('provider_post_id');
            foreach ($posts as $p) {
                foreach ($p->targets as $t) {
                    if ($t->retry_provider_post_id !== null && $t->status === SocialPostTargetStatus::Pending) {
                        $byProviderId->put($t->retry_provider_post_id, $p);
                    }
                }
            }

            try {
                $results = $this->providers->named((string) $provider)
                    ->resultsFor($byProviderId->keys()->map(fn ($k) => (string) $k)->values()->all());
            } catch (Throwable) {
                continue;
            }

            foreach ($results as $result) {
                $post = $byProviderId->get((string) ($result['post_id'] ?? ''));
                $account = $post?->targets
                    ->first(fn ($t) => $t->account->provider_account_id === (string) ($result['social_account_id'] ?? ''))
                    ?->account;

                if ($post !== null && $account !== null) {
                    self::applyResult($post, $account, $result, 'reconcile');
                }
            }
        }
    }
}
