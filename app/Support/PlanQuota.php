<?php

namespace App\Support;

use App\Casts\UtcDateTime;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SpaceStatus;
use App\Exceptions\PlanFeatureRequired;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\Media\MediaLimits;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Plan limits, read from the plans table. No number lives here: a user's
 * plan comes from their active subscription (none = Free), and the ceiling
 * comes from that plan's `quotas`.
 *
 * A null ceiling means no limit — every check below treats it as "always
 * room", which is what a paid plan buys.
 */
class PlanQuota
{
    /**
     * Feature gates (plans v2): a `features` flag each, false on Free.
     * Refused with 402 plan_required, feature = the key. An endpoint that
     * does one of these calls requireFeature() first — including the ones
     * not built yet (alt_text, ai_reply_suggestions, comment_to_dm).
     */
    public const FEATURES = ['ai_caption', 'insight', 'spaces', 'alt_text', 'ai_reply_suggestions', 'comment_to_dm'];

    /**
     * @throws PlanFeatureRequired
     */
    public static function requireFeature(User $user, string $feature, string $field = 'plan'): void
    {
        if (! Workspace::owner($user)->plan()->feature($feature)) {
            throw PlanFeatureRequired::feature($user, $feature, $field);
        }
    }

    public static function hasFeature(User $user, string $feature): bool
    {
        return Workspace::owner($user)->plan()->feature($feature);
    }

    /** AI alt text for media. No endpoint yet: the one that ships calls this. */
    public static function requireAltText(User $user, string $field = 'alt_text'): void
    {
        static::requireFeature($user, 'alt_text', $field);
    }

    /** AI-suggested replies in Engage. No endpoint yet: the one that ships calls this. */
    public static function requireAiReplySuggestions(User $user, string $field = 'reply'): void
    {
        static::requireFeature($user, 'ai_reply_suggestions', $field);
    }

    /** Automatic comment → DM. No endpoint yet: the one that ships calls this. */
    public static function requireCommentToDm(User $user, string $field = 'comment_to_dm'): void
    {
        static::requireFeature($user, 'comment_to_dm', $field);
    }

    /** Days ahead a post may be scheduled, or null for no limit. */
    public static function scheduleWindowDays(User $user): ?int
    {
        return Workspace::owner($user)->plan()->quota('schedule_window_days');
    }

    /** Queued posts (scheduled + waiting for approval) allowed, or null. */
    public static function queueLimit(User $user): ?int
    {
        return Workspace::owner($user)->plan()->quota('queued_posts');
    }

    /** Distinct approval clients allowed at once, or null. */
    public static function approvalClientsLimit(User $user): ?int
    {
        return Workspace::owner($user)->plan()->quota('approval_clients');
    }

    /**
     * A time inside the plan's scheduling window? Compared in UTC against
     * now: exactly `days` ahead is still in. Null (send now / on approval)
     * always is.
     *
     * @throws PlanFeatureRequired
     */
    public static function requireScheduleWindow(User $user, ?CarbonInterface $at, string $field = 'scheduled_at'): void
    {
        $days = static::scheduleWindowDays($user);

        if ($days !== null && $at !== null
            && $at->copy()->utc()->greaterThan(now()->utc()->addDays($days))) {
            throw PlanFeatureRequired::scheduleWindow($user, $days, $field);
        }
    }

    /**
     * The workspace's queue: posts that will go out later — scheduled for a
     * future slot, or held for approval (editor or client, revision too).
     * Drafts, published, failed and cancelled posts are not in it, and
     * neither is a send-now on its way out.
     */
    public static function queuedPosts(User $user): int
    {
        $owner = Workspace::owner($user);

        return SocialPost::query()
            ->whereIn('user_id', Workspace::memberIds($owner))
            ->where('status', SocialPostStatus::Scheduled)
            ->where(fn ($q) => $q
                ->whereIn('approval', [
                    SocialPostApproval::Awaiting->value,
                    SocialPostApproval::Client->value,
                    SocialPostApproval::Revision->value,
                ])
                ->orWhere(fn ($q) => $q
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '>', UtcDateTime::bind(now()))))
            ->count();
    }

    /**
     * Room for $adding more queued posts? Posts already queued are never
     * touched — only adding one past the limit is refused.
     *
     * @throws PlanFeatureRequired
     */
    public static function requireQueueRoom(User $user, int $adding = 1, string $field = 'scheduled_at'): void
    {
        $limit = static::queueLimit($user);

        if ($limit !== null && $adding > 0 && static::queuedPosts($user) + $adding > $limit) {
            throw PlanFeatureRequired::queueLimit($user, $limit, $field);
        }
    }

    /**
     * Who the workspace is asking for approval right now: one identity per
     * open (live) approval link — its client_name, trimmed and lower-cased,
     * when the editor named one; otherwise the link itself (an unnamed link
     * is a client we cannot tell apart from any other). Two links for
     * "Acme" are one client; two unnamed links are two.
     *
     * @return Collection<int, string>
     */
    public static function approvalClients(User $user, ?SocialApprovalLink $except = null): Collection
    {
        return SocialApprovalLink::query()
            ->where('user_id', Workspace::owner($user)->id)
            ->live()
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->get(['id', 'client_name'])
            ->toBase()
            ->map(fn (SocialApprovalLink $link) => static::approvalIdentity($link->client_name, $link->id))
            ->unique()
            ->values();
    }

    public static function approvalIdentity(?string $clientName, ?int $linkId = null): string
    {
        $name = mb_strtolower(trim((string) $clientName));

        return $name !== '' ? 'name:'.$name : 'link:'.($linkId ?? 'new');
    }

    /**
     * Room for an approval link for this client? $except is the link being
     * renamed (its own identity does not count against it).
     *
     * @throws PlanFeatureRequired
     */
    public static function requireApprovalClient(User $user, ?string $clientName, ?SocialApprovalLink $except = null, string $field = 'client_name'): void
    {
        $limit = static::approvalClientsLimit($user);
        if ($limit === null) {
            return;
        }

        $identities = static::approvalClients($user, $except)
            ->push(static::approvalIdentity($clientName, $except?->id))
            ->unique();

        if ($identities->count() > $limit) {
            throw PlanFeatureRequired::approvalClients($user, $limit, $field);
        }
    }

    /**
     * Spaces created and not deleted (draft + published + archived).
     */
    public static function totalUsed(User $user): int
    {
        return $user->spaces()->count();
    }

    /**
     * Active = published. Archiving frees a slot.
     */
    public static function activeUsed(User $user): int
    {
        return $user->spaces()->where('status', SpaceStatus::Published)->count();
    }

    public static function totalLimit(User $user): ?int
    {
        return $user->plan()->quota('spaces_total');
    }

    public static function activeLimit(User $user): ?int
    {
        return $user->plan()->quota('spaces_active');
    }

    /**
     * Room for one more Space at all?
     */
    public static function canCreate(User $user): bool
    {
        $limit = static::totalLimit($user);

        return $limit === null || static::totalUsed($user) < $limit;
    }

    /**
     * Room for one more *published* Space?
     */
    public static function canPublish(User $user): bool
    {
        $limit = static::activeLimit($user);

        return $limit === null || static::activeUsed($user) < $limit;
    }

    /**
     * Bytes counted against the storage quota. Pending rows count too —
     * otherwise presigning N huge files in parallel bypasses the check.
     *
     * Old versions are charged for as well. They are real objects sitting in
     * the bucket, and billing them is what makes "you can free up 340 MB"
     * a true sentence rather than an invitation to hoard.
     */
    public static function storageUsed(User $user): int
    {
        /* withTrashed on both halves. A file in the Trash is still an object
           in the bucket and still billed, so it still counts — that is what
           makes "empty the Trash to free 340 MB" a true sentence rather than
           an invitation to park 20 GB in the bin for a month. Without it the
           soft delete would have silently handed everyone free storage. */
        /* A pending row only holds its space while its presigned URL can
           still be used. Past the window the PUT can no longer start, so the
           reservation is dead weight until PruneStaleUploads removes it —
           counting it would lock people out of uploads they never made. */
        $current = (int) $user->files()
            ->withTrashed()
            ->where(fn ($q) => $q
                ->where('status', File::STATUS_READY)
                ->orWhere(fn ($q) => $q
                    ->where('status', File::STATUS_PENDING)
                    ->where('created_at', '>=', now()->subMinutes(File::PRESIGN_TTL_MINUTES))))
            ->sum('size_bytes');

        $history = (int) FileVersion::query()
            ->whereIn('file_id', $user->files()->withTrashed()->select('id'))
            ->sum('size_bytes');

        return $current + $history;
    }

    /**
     * The total storage ceiling (Media v2, config/media.php): Free 1 GB;
     * paid tiers have no total — they are bounded by the Keep quota and
     * retention instead (MediaQuota). Rented blocks are gone.
     */
    public static function storageLimit(User $user): ?int
    {
        return MediaLimits::totalCap($user);
    }

    /** Same as storageLimit(): there is nothing on top of the plan any more. */
    public static function planStorageLimit(User $user): ?int
    {
        return static::storageLimit($user);
    }

    /**
     * Over the ceiling: the plan shrank (a downgrade to Free). Uploads stop
     * until it fits; nothing is deleted and every link keeps working.
     */
    public static function readOnly(User $user): bool
    {
        $limit = static::storageLimit($user);

        return $limit !== null && static::storageUsed($user) > $limit;
    }

    /**
     * Room for $bytes more?
     */
    public static function canStore(User $user, int $bytes): bool
    {
        $limit = static::storageLimit($user);

        return $limit === null || static::storageUsed($user) + $bytes <= $limit;
    }

    /**
     * Legacy: the old publishing allowance (`social_posts_monthly`), still
     * readable for older screens. Nothing charges against it any more —
     * the plan's Monthly Credits (`credits_monthly`) replaced it.
     */
    public static function socialPostsLimit(User $user): ?int
    {
        $owner = Workspace::owner($user);
        $limit = $owner->plan()->quota('social_posts_monthly');

        return $limit === null ? null : $limit * Workspace::seats($owner);
    }

    /**
     * Room to send to these destinations (a count, or per destination its
     * platform / [platform, caption]): whatever this cycle's Monthly
     * Credits do not cover must be in Top-up Credits.
     *
     * @param  int|iterable<mixed>  $destinations
     */
    public static function canPostSocial(User $user, int|iterable $destinations = 1): bool
    {
        return Credits::balance($user) >= Credits::sendPrice($user, $destinations);
    }

    /**
     * Asking a client to approve: on every plan since plans v2 (Free with
     * the approval_clients and scheduling limits). A plan row with the flag
     * off still refuses — only when a new request starts; links already
     * sent keep working through a downgrade.
     *
     * @throws PlanFeatureRequired
     */
    public static function requireClientApprovals(User $user, string $field = 'approval'): void
    {
        if (! Workspace::owner($user)->plan()->feature('client_approvals')) {
            throw PlanFeatureRequired::approvalClients($user, 0, $field);
        }
    }

    /**
     * Room to connect one more social account? Free stops at three. An
     * account already connected is never cut off by a downgrade — only
     * adding one is refused. Only usable rows count: one left under a
     * previous relay is a reconnect waiting to happen, not a slot.
     */
    public static function canConnectSocialAccount(User $user): bool
    {
        $owner = Workspace::owner($user);
        $limit = $owner->plan()->quota('social_accounts');

        return $limit === null
            || $owner->socialAccounts()->usable()->count() < $limit;
    }

    /**
     * @return array{active_used: int, active_limit: int|null, total_used: int, total_limit: int|null, plan: string}
     */
    public static function meta(User $user): array
    {
        return [
            'active_used' => static::activeUsed($user),
            'active_limit' => static::activeLimit($user),
            'total_used' => static::totalUsed($user),
            'total_limit' => static::totalLimit($user),
            'plan' => $user->plan()->key->value,
        ];
    }
}
