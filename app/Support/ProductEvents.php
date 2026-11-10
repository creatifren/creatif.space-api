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

/**
 * The tracking plan's events, built in one place so every trigger sends
 * the same names and property keys. Callers decide WHEN (one call per real
 * occurrence); this decides WHAT. Ids are ULIDs, never emails; no captions,
 * names or client names leave here.
 */
final class ProductEvents
{
    /** The UTM keys a signup carries, when it has them. */
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign'];

    private static function analytics(): Analytics
    {
        return app(Analytics::class);
    }

    /**
     * A user row was created.
     *
     * @param  array<string, string|null>  $attribution  ref and utm_* — only the present ones are sent
     */
    public static function signedUp(User $user, string $method, array $attribution = []): void
    {
        $extra = array_filter(
            array_intersect_key($attribution, array_flip(['ref', ...self::UTM])),
            fn ($v) => is_string($v) && $v !== '',
        );

        self::analytics()->capture($user->ulid, 'user_signed_up', ['method' => $method, 'plan' => 'free'] + $extra);
        self::analytics()->set($user->ulid, [
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

    /**
     * A waitlist sign-up was confirmed. No account and no raw address: the
     * distinct_id is the address's sha256.
     *
     * @param  array<string, string>  $utm
     */
    public static function waitlistJoined(string $email, string $list, bool $referred, bool $refCodePresent, array $utm = []): void
    {
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
            self::analytics()->set($user->ulid, ['plan' => $now]);
        }
    }

    /** A social account row was created for $user (sync or webhook). */
    public static function channelConnected(User $user, SocialAccount $account): void
    {
        $count = self::connectedCount($user);

        self::analytics()->capture($user->ulid, 'channel_connected', [
            'network' => $account->platform->value,
            // Ever: a reconnect of an old row is not a new row, so only the very first row counts.
            'is_first_channel' => $user->socialAccounts()->whereKeyNot($account->id)->doesntExist(),
            'channels_count' => $count,
        ]);
        self::analytics()->set($user->ulid, ['channels_count' => $count]);
    }

    public static function channelsCount(User $user): void
    {
        self::analytics()->set($user->ulid, ['channels_count' => self::connectedCount($user)]);
    }

    private static function connectedCount(User $user): int
    {
        return $user->socialAccounts()->usable()->count();
    }

    /** @param  'draft'|'scheduled'|'approval'|'now'  $status */
    public static function postCreated(SocialPost $post, string $status): void
    {
        $post->loadMissing('targets.account', 'user');

        self::analytics()->capture($post->user->ulid, 'post_created', [
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

        self::analytics()->capture($owner->ulid, 'approval_link_created', [
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

        self::analytics()->capture($link->user->ulid, 'approval_link_opened', [
            'link_id' => $link->ulid,
            'posts_count' => $postsCount,
        ]);
    }

    /** @param  'approved'|'changes_requested'|'retracted'  $decision */
    public static function approvalDecision(SocialApprovalLink $link, SocialPost $post, string $decision): void
    {
        self::analytics()->capture($link->user->ulid, 'approval_decision_made', [
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
     * result landed — a redelivered result finds it already there and
     * sends nothing.
     */
    public static function publishResult(SocialPost $post, SocialPostTarget $target, ?SocialPostTargetStatus $before, ?string $error): void
    {
        $post->loadMissing('user');
        $target->loadMissing('account');
        $network = $target->account?->platform->value;

        if ($target->status === SocialPostTargetStatus::Published && $before !== SocialPostTargetStatus::Published) {
            $firstEver = ! SocialPostTarget::query()
                ->whereKeyNot($target->id)
                ->where('status', SocialPostTargetStatus::Published)
                ->whereHas('post', fn ($q) => $q->where('user_id', $post->user_id))
                ->exists();

            self::analytics()->capture($post->user->ulid, 'post_published', [
                'post_id' => $post->ulid,
                'network' => $network,
                'credits_charged' => (int) $target->charged_credits + (int) $target->charged_monthly,
                'via' => match (true) {
                    $post->approval === SocialPostApproval::Approved => 'after_approval',
                    $post->scheduled_at !== null => 'scheduled',
                    default => 'immediate',
                },
                'is_first_publish' => $firstEver,
            ]);

            return;
        }

        if ($target->status === SocialPostTargetStatus::Failed && $before !== SocialPostTargetStatus::Failed) {
            self::analytics()->capture($post->user->ulid, 'post_publish_failed', [
                'post_id' => $post->ulid,
                'network' => $network,
                'error_code' => self::errorCode($error),
            ]);
        }
    }

    /**
     * A platform's error text as a short code. The text itself is not sent:
     * it can quote the caption or name the account.
     */
    public static function errorCode(?string $error): string
    {
        $e = strtolower((string) $error);

        return match (true) {
            $e === '' => 'unknown',
            str_contains($e, 'rate') || str_contains($e, 'too many') || str_contains($e, 'limit reached') => 'rate_limited',
            str_contains($e, 'token') || str_contains($e, 'expired') || str_contains($e, 'unauthori')
                || str_contains($e, 'permission') || str_contains($e, 'reconnect') || str_contains($e, 'auth') => 'auth',
            str_contains($e, 'duplicate') => 'duplicate',
            str_contains($e, 'media') || str_contains($e, 'video') || str_contains($e, 'image')
                || str_contains($e, 'aspect') || str_contains($e, 'format') || str_contains($e, 'file') => 'media',
            str_contains($e, 'caption') || str_contains($e, 'character') || str_contains($e, 'length')
                || str_contains($e, 'too long') || str_contains($e, 'text') => 'content',
            str_contains($e, 'timeout') || str_contains($e, 'timed out') || str_contains($e, 'unavailable')
                || str_contains($e, 'server error') => 'platform_unavailable',
            default => 'other',
        };
    }

    /**
     * @param  'subscription'|'credits'  $kind
     */
    public static function checkoutStarted(User $user, string $kind, PlanKey|string|null $plan, ?string $period, int $amountCents, bool $trial = false): void
    {
        self::analytics()->capture($user->ulid, 'checkout_started', [
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
        self::analytics()->capture($user->ulid, 'trial_started', [
            'plan' => self::key($plan),
            'period' => $period,
            'trial_days' => $days,
        ]);
    }

    public static function subscriptionStarted(User $user, PlanKey|string $plan, string $period, int $amountCents): void
    {
        self::analytics()->capture($user->ulid, 'subscription_started', [
            'plan' => self::key($plan),
            'period' => $period,
            'amount_usd' => round($amountCents / 100, 2),
        ]);
    }

    public static function subscriptionCanceled(User $user, PlanKey|string $plan): void
    {
        self::analytics()->capture($user->ulid, 'subscription_canceled', ['plan' => self::key($plan)]);
    }

    public static function creditsPurchased(User $user, int $amountCents, int $credits): void
    {
        self::analytics()->capture($user->ulid, 'credits_purchased', [
            'amount_usd' => round($amountCents / 100, 2),
            'credits' => $credits,
        ]);
    }

    /** @param  'dm'|'comment'|'private_reply'  $kind */
    public static function replySent(User $user, string $kind, ?string $network): void
    {
        self::analytics()->capture($user->ulid, 'engage_reply_sent', ['kind' => $kind, 'network' => $network]);
    }
}
