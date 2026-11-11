<?php

namespace App\Support;

use App\Enums\PlanKey;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Analytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The tracking plan's events, built in one place so every trigger sends
 * the same names and property keys. Callers decide WHEN (one call per real
 * occurrence); this decides WHAT. Ids are ULIDs, never emails; no captions,
 * names or client names leave here.
 */
final class ProductEvents
{
    /** The UTM keys a signup carries, when it has them. */
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** Which page or button a signup started from: a slug, never a referral code. */
    public const ENTRY_PATTERN = '/^[a-z0-9-]{1,40}$/';

    /** X-Creatif-Platform: where the app runs. Anything else is ignored. */
    public const PLATFORM_HEADER = 'X-Creatif-Platform';

    public const PLATFORMS = ['web', 'android_app', 'android_twa'];

    /** Events also sent under a second name while dashboards move over. */
    private const ALIASES = [
        'approval_link_created' => 'approval_link_sent',
        'approval_decision_made' => 'approval_decided',
    ];

    /** Events that carry plan + platform (the tracking plan's core five). */
    private const WITH_CONTEXT = ['user_signed_up', 'post_published', 'approval_link_created', 'approval_decision_made', 'channel_connected'];

    private static function analytics(): Analytics
    {
        return app(Analytics::class);
    }

    /**
     * Where this request comes from: the app's X-Creatif-Platform header
     * when it is one we know; a person's own request without it is the web
     * app; a webhook, job or console run is none (null).
     */
    public static function platform(): ?string
    {
        if (! app()->bound('request') || app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $request = request();
        $given = strtolower(trim((string) $request->headers->get(self::PLATFORM_HEADER, '')));
        if (in_array($given, self::PLATFORMS, true)) {
            return $given;
        }

        $uri = (string) $request->route()?->uri();

        return $uri === '' || str_starts_with($uri, 'api/v1/webhooks') ? null : 'web';
    }

    /**
     * Every product event goes through here: none for an internal account
     * (staff, ANALYTICS_INTERNAL_EMAILS, hello@creatif.space), plan and
     * platform on the core five, and the alias name sent too while it lasts.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function capture(User $user, string $event, array $properties): void
    {
        if (Analytics::isInternal($user)) {
            return;
        }

        if (in_array($event, self::WITH_CONTEXT, true)) {
            $properties += ['plan' => self::planOf($user), 'platform' => self::platform()];
        }

        self::analytics()->capture($user->ulid, $event, $properties);

        $alias = self::ALIASES[$event] ?? null;
        $until = (string) config('services.posthog.alias_until', '');
        if ($alias !== null && ($until === '' || now()->lessThanOrEqualTo($until))) {
            self::analytics()->capture($user->ulid, $alias, $properties);
        }
    }

    /** @param  array<string, mixed>  $properties */
    private static function set(User $user, array $properties): void
    {
        if (! Analytics::isInternal($user)) {
            self::analytics()->set($user->ulid, $properties);
        }
    }

    /**
     * A user row was created.
     *
     * @param  array<string, string|null>  $attribution  ref, utm_* and entry — only the present ones are sent
     * @param  array<string, string>  $context  sent as is, e.g. ['platform' => 'android_app'] for the app shell
     */
    public static function signedUp(User $user, string $method, array $attribution = [], array $context = []): void
    {
        $extra = array_filter(
            array_intersect_key($attribution, array_flip(['ref', ...self::UTM])),
            fn ($v) => is_string($v) && $v !== '',
        );

        // Analytics only: entry is never looked up as a referral code.
        $entry = self::entry($attribution['entry'] ?? null);
        if ($entry !== null) {
            $extra['entry'] = $entry;
            $extra['$set_once'] = ['initial_entry' => $entry];
        }

        self::capture($user, 'user_signed_up', ['method' => $method, 'plan' => 'free'] + $context + $extra);
        self::set($user, [
            'plan' => 'free',
            'signup_method' => $method,
            'signed_up_at' => ($user->created_at ?? now())->toIso8601String(),
            'is_internal' => Analytics::isInternal($user),
        ]);
    }

    /**
     * UTM values from a request's query/body, trimmed to something sane.
     *
     * @return array<string, string>
     */
    public static function utmFrom(Request $request): array
    {
        $out = [];
        foreach (self::UTM as $key) {
            $value = $request->input($key);
            if (is_string($value) && trim($value) !== '') {
                $out[$key] = substr(trim($value), 0, 100);
            }
        }

        return $out;
    }

    /** A valid entry slug, or null: anything else is dropped, not rejected. */
    public static function entry(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::ENTRY_PATTERN, $value) === 1 ? $value : null;
    }

    /** The entry slug from a request's query/body, or null. */
    public static function entryFrom(Request $request): ?string
    {
        return self::entry($request->input('entry'));
    }

    /**
     * A waitlist sign-up was confirmed. No account and no raw address: the
     * distinct_id is the address's sha256.
     *
     * @param  array<string, string>  $utm
     */
    public static function waitlistJoined(string $email, string $list, bool $referred, bool $refCodePresent, array $utm = []): void
    {
        if (Analytics::isInternalEmail($email)) {
            return;
        }
        $extra = array_filter(array_intersect_key($utm, array_flip(self::UTM)), fn (string $v) => $v !== '');

        self::analytics()->capture(hash('sha256', strtolower($email)), 'waitlist_joined', [
            'list' => $list,
            'referred' => $referred,
            'ref_code_present' => $refCodePresent,
        ] + $extra);
    }

    /** A waitlist referrer reached the priority batch. Same hashed id as waitlist_joined. */
    public static function waitlistPriorityReached(string $email, int $referrals): void
    {
        if (Analytics::isInternalEmail($email)) {
            return;
        }
        self::analytics()->capture(hash('sha256', strtolower($email)), 'waitlist_priority_reached', ['list' => 'mcp', 'referral_count' => $referrals]);
    }

    /** A plan key as sent: the enum's value. */
    public static function key(PlanKey|string|null $plan): ?string
    {
        return $plan instanceof PlanKey ? $plan->value : $plan;
    }

    /** The plan $user is on now. */
    public static function planOf(User $user): string
    {
        return (string) self::key($user->plan()->key);
    }

    /** $set plan — call when it may have moved; sends only on a change from $before. */
    public static function planChanged(User $user, PlanKey|string|null $before = null): void
    {
        $now = self::planOf($user);
        if ($before === null || self::key($before) !== $now) {
            self::set($user, ['plan' => $now]);
        }
    }

    /** A social account row was created for $user (sync or webhook). */
    public static function channelConnected(User $user, SocialAccount $account): void
    {
        $count = self::connectedCount($user);

        self::capture($user, 'channel_connected', [
            'network' => $account->platform->value,
            // Ever: a reconnect of an old row is not a new row, so only the very first row counts.
            'is_first_channel' => $user->socialAccounts()->whereKeyNot($account->id)->doesntExist(),
            'channels_count' => $count,
        ]);
        self::set($user, ['channels_count' => $count]);
    }

    public static function channelsCount(User $user): void
    {
        self::set($user, ['channels_count' => self::connectedCount($user)]);
    }

    private static function connectedCount(User $user): int
    {
        return $user->socialAccounts()->usable()->count();
    }

    /** @param  'draft'|'scheduled'|'approval'|'now'  $status */
    public static function postCreated(SocialPost $post, string $status): void
    {
        $post->loadMissing('targets.account', 'user');

        self::capture($post->user, 'post_created', [
            'post_id' => $post->ulid,
            'status' => $status,
            'networks' => $post->targets->map(fn ($t) => $t->account->platform->value)->unique()->values()->all(),
            'destinations_count' => $post->targets->count(),
            'has_media' => ! empty($post->media),
            'approval_required' => $post->approval !== null,
        ]);
    }

    public static function approvalLinkCreated(SocialApprovalLink $link, int $postsCount, ?int $hours): void
    {
        $owner = $link->user;

        self::capture($owner, 'approval_link_created', [
            'link_id' => $link->ulid,
            'posts_count' => $postsCount,
            'expires_in_days' => $hours === null ? null : round($hours / 24, 1),
            'is_first_link' => SocialApprovalLink::query()->where('user_id', $owner->id)->whereKeyNot($link->id)->doesntExist(),
        ]);
    }

    /**
     * A client opened the link. On the OWNER's id: the client has no
     * account. Once per link per visitor (IP) per day.
     */
    public static function approvalLinkOpened(SocialApprovalLink $link, int $postsCount, Request $request): void
    {
        $visitor = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $key = 'analytics:link-opened:'.$link->id.':'.$visitor.':'.now()->toDateString();

        if (! cache()->add($key, 1, now()->endOfDay())) {
            return;
        }

        self::capture($link->user, 'approval_link_opened', [
            'link_id' => $link->ulid,
            'posts_count' => $postsCount,
        ]);
    }

    /** @param  'approved'|'changes_requested'|'retracted'  $decision */
    public static function approvalDecision(SocialApprovalLink $link, SocialPost $post, string $decision): void
    {
        self::capture($link->user, 'approval_decision_made', [
            'link_id' => $link->ulid,
            'post_id' => $post->ulid,
            'decision' => $decision,
            'hours_since_link' => $link->created_at === null
                ? null
                : round($link->created_at->diffInMinutes(now(), true) / 60, 1),
        ]);
    }

    /**
     * One destination's outcome, called with the status it had before the
     * result landed. Only a move out of Pending counts, and each outcome of
     * a target is sent once ever: a marker per (target, outcome) in the
     * cache (database store in prod; written in the caller's transaction,
     * so a rolled-back result leaves none). A redelivered or replayed
     * result sends nothing.
     *
     * @param  'webhook'|'reconcile'|'job'  $source
     */
    public static function publishResult(SocialPost $post, SocialPostTarget $target, ?SocialPostTargetStatus $before, ?string $error, string $source = 'job'): void
    {
        if ($before !== SocialPostTargetStatus::Pending
            || ! in_array($target->status, [SocialPostTargetStatus::Published, SocialPostTargetStatus::Failed], true)
            || ! Cache::add('publish-event:'.$target->id.':'.$target->status->value, true)) {
            return;
        }

        $post->loadMissing('user');
        $target->loadMissing('account');
        $network = $target->account?->platform->value;

        if ($target->status === SocialPostTargetStatus::Published) {
            $firstEver = ! SocialPostTarget::query()
                ->whereKeyNot($target->id)
                ->where('status', SocialPostTargetStatus::Published)
                ->whereHas('post', fn ($q) => $q->where('user_id', $post->user_id))
                ->exists();

            self::capture($post->user, 'post_published', [
                // Where the post was made: this runs in a webhook or job.
                'platform' => self::platform() ?? $post->client_platform,
                'post_id' => $post->ulid,
                'network' => $network,
                'credits_charged' => (int) $target->charged_credits + (int) $target->charged_monthly,
                'via' => match (true) {
                    $post->approval === SocialPostApproval::Approved => 'after_approval',
                    $post->scheduled_at !== null => 'scheduled',
                    default => 'immediate',
                },
                'is_first_publish' => $firstEver,
                'source' => $source,
            ]);

            return;
        }

        $failure = PublishFailure::classify($error, $network);

        self::capture($post->user, 'post_publish_failed', [
            'post_id' => $post->ulid,
            'network' => $network,
            'error_code' => $failure['error_code'],
            'reason_code' => $failure['reason_code'],
            'attempts' => (int) $target->attempts + 1,
            'provider' => $post->provider,
            'source' => $source,
        ]);
    }

    /**
     * A platform's error text as a short code. The text itself is not sent:
     * it can quote the caption or name the account.
     */
    public static function errorCode(?string $error): string
    {
        return PublishFailure::classify($error)['error_code'];
    }

    /**
     * @param  'subscription'|'credits'  $kind
     */
    public static function checkoutStarted(User $user, string $kind, PlanKey|string|null $plan, ?string $period, int $amountCents, bool $trial = false): void
    {
        self::capture($user, 'checkout_started', [
            'kind' => $kind,
            'plan' => self::key($plan),
            'period' => $period,
            'amount_usd' => round($amountCents / 100, 2),
            'trial' => $trial,
        ]);
    }

    /** Once per subscription: Creem says its trial began. */
    public static function trialStarted(User $user, PlanKey|string $plan, string $period, int $days): void
    {
        self::capture($user, 'trial_started', [
            'plan' => self::key($plan),
            'period' => $period,
            'trial_days' => $days,
        ]);
    }

    /** @param  'creem'|'google'  $provider */
    public static function subscriptionStarted(User $user, PlanKey|string $plan, string $period, int $amountCents, string $provider = 'creem'): void
    {
        self::capture($user, 'subscription_started', [
            'plan' => self::key($plan),
            'period' => $period,
            'amount_usd' => round($amountCents / 100, 2),
            'provider' => $provider,
        ]);
    }

    public static function subscriptionCanceled(User $user, PlanKey|string $plan): void
    {
        self::capture($user, 'subscription_canceled', ['plan' => self::key($plan)]);
    }

    public static function creditsPurchased(User $user, int $amountCents, int $credits): void
    {
        self::capture($user, 'credits_purchased', [
            'amount_usd' => round($amountCents / 100, 2),
            'credits' => $credits,
        ]);
    }

    /** @param  'dm'|'comment'|'private_reply'  $kind */
    public static function replySent(User $user, string $kind, ?string $network): void
    {
        self::capture($user, 'engage_reply_sent', ['kind' => $kind, 'network' => $network]);
    }
}
