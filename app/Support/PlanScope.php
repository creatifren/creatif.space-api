<?php

namespace App\Support;

use App\Casts\UtcDateTime;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Models\File;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Media\MediaLinks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * The Content Plan (Meeting #02): which posts an approval link shows, and
 * what the client may do with each.
 *
 * A fixed-list link (scope null) is what it always was: the posts attached
 * to it. A scoped link is a date range in the owner's timezone, optionally
 * narrowed to some channels, and shows every workspace post inside it —
 * including posts added after the link was sent:
 *
 * - scheduled posts (any approval state: held for the client, changes
 *   requested, approved, or scheduled without approval);
 * - published posts — the client sees what went out that month (state
 *   "published", never decidable); failed and cancelled stay out;
 * - drafts only when the owner marked them for review (review_requested_at)
 *   and they have a date.
 *
 * Dates are calendar days in the link's timezone; the range is stored as
 * UTC instants [starts, ends) and compared with scheduled_at (UTC).
 */
final class PlanScope
{
    /** The longest Content Plan, in days. */
    public const MAX_DAYS = 93;

    public const STATE_WAITING = 'waiting';

    public const STATE_CHANGES = 'changes_requested';

    public const STATE_APPROVED = 'approved';

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_DRAFT = 'draft';

    public const STATE_PUBLISHED = 'published';

    /**
     * The link's posts as a query: the attached list, or the scope.
     *
     * @return Builder<SocialPost>|BelongsToMany<SocialPost, SocialApprovalLink>
     */
    public static function posts(SocialApprovalLink $link): Builder|BelongsToMany
    {
        if (! $link->isScoped()) {
            return $link->posts();
        }

        return self::inRange(self::workspacePosts($link), $link->scope_starts_at, $link->scope_ends_at, $link->scope_account_ids);
    }

    /**
     * The link's posts narrowed by the page's filters — a channel (account
     * ulid) and a date range in the link's timezone — always inside the scope.
     *
     * @return Builder<SocialPost>|BelongsToMany<SocialPost, SocialApprovalLink>
     */
    public static function filtered(SocialApprovalLink $link, ?string $channel, ?string $from, ?string $to): Builder|BelongsToMany
    {
        $query = self::posts($link);
        $tz = self::timezone($link);

        if ($channel !== null && $channel !== '') {
            $accountId = SocialAccount::query()->where('ulid', $channel)
                ->where('user_id', Workspace::owner($link->user)->id)->value('id');
            // An unknown channel matches nothing rather than everything.
            $query->whereHas('targets', fn ($t) => $t->where('social_account_id', $accountId ?? 0));
        }
        if (($from !== null && $from !== '') || ($to !== null && $to !== '')) {
            self::whereWithin(
                $query,
                $from !== null && $from !== '' ? CarbonImmutable::parse($from, $tz)->startOfDay() : null,
                $to !== null && $to !== '' ? CarbonImmutable::parse($to, $tz)->startOfDay()->addDay() : null,
            );
        }

        return $query;
    }

    /**
     * The UTC bounds of calendar dates in a timezone: [from 00:00, to+1 00:00).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bounds(string $from, string $to, string $timezone): array
    {
        return [
            CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay()->utc(),
        ];
    }

    public static function timezone(SocialApprovalLink $link): string
    {
        return $link->scope_timezone ?: (string) config('app.timezone');
    }

    /**
     * Approval states a client may decide from on this link.
     *
     * @return list<SocialPostApproval>
     */
    public static function decidableFrom(SocialApprovalLink $link): array
    {
        // On a Content Plan, a post the editor held for approval is waiting
        // on the client too; a fixed list switched those to Client on creation.
        return $link->isScoped()
            ? [SocialPostApproval::Awaiting, SocialPostApproval::Client, SocialPostApproval::Revision]
            : [SocialPostApproval::Client, SocialPostApproval::Revision];
    }

    /** Where a post stands, in the plan's words. */
    public static function state(SocialPost $post): string
    {
        if ($post->status === SocialPostStatus::Draft) {
            return self::STATE_DRAFT;
        }
        if ($post->status === SocialPostStatus::Published) {
            return self::STATE_PUBLISHED;
        }

        return match ($post->approval) {
            SocialPostApproval::Awaiting, SocialPostApproval::Client => self::STATE_WAITING,
            SocialPostApproval::Revision => self::STATE_CHANGES,
            SocialPostApproval::Approved => self::STATE_APPROVED,
            default => self::STATE_SCHEDULED,
        };
    }

    /**
     * May the client decide on this post now? Only held posts, and on Free
     * only those inside the scheduling window (the next 7 days): the rest
     * show, with the date they become decidable.
     *
     * @return array{decidable: bool, decidable_from: ?string}
     */
    public static function decidability(SocialApprovalLink $link, SocialPost $post): array
    {
        $held = $post->status === SocialPostStatus::Scheduled
            && in_array($post->approval, self::decidableFrom($link), true);

        if (! $held) {
            return ['decidable' => false, 'decidable_from' => null];
        }

        $days = PlanQuota::scheduleWindowDays($link->user);
        if ($days === null || $post->scheduled_at === null) {
            return ['decidable' => true, 'decidable_from' => null];
        }

        $opens = $post->scheduled_at->copy()->utc()->subDays($days);

        return $opens->isPast()
            ? ['decidable' => true, 'decidable_from' => null]
            : ['decidable' => false, 'decidable_from' => $opens->toIso8601ZuluString()];
    }

    /**
     * Posts per state on the link (scoped or fixed), plus the total.
     *
     * @return array<string, int>
     */
    public static function counts(SocialApprovalLink $link): array
    {
        $posts = self::posts($link)->get(['social_posts.id', 'social_posts.status', 'social_posts.approval']);
        $counts = array_fill_keys([self::STATE_WAITING, self::STATE_CHANGES, self::STATE_APPROVED, self::STATE_SCHEDULED, self::STATE_DRAFT, self::STATE_PUBLISHED], 0);
        foreach ($posts as $post) {
            $counts[self::state($post)]++;
        }

        return ['total' => $posts->count()] + $counts;
    }

    /**
     * The scope as the API shows it (null for a fixed-list link).
     *
     * @return array<string, mixed>|null
     */
    public static function toArray(SocialApprovalLink $link): ?array
    {
        if (! $link->isScoped()) {
            return null;
        }

        $ids = $link->scope_account_ids;

        return [
            'date_from' => $link->scope_from?->format('Y-m-d'),
            'date_to' => $link->scope_to?->format('Y-m-d'),
            'timezone' => self::timezone($link),
            'starts_at' => $link->scope_starts_at?->toIso8601ZuluString(),
            'ends_at' => $link->scope_ends_at?->toIso8601ZuluString(),
            'channel_ids' => $ids === null ? null
                : SocialAccount::query()->whereIn('id', $ids)->orderBy('id')->pluck('ulid')->all(),
        ];
    }

    /**
     * The channels a client can filter by: the scope's, or every one the
     * workspace has posts with on the link.
     *
     * @return list<array{id: string, platform: string, handle: ?string}>
     */
    public static function channels(SocialApprovalLink $link): array
    {
        $owner = Workspace::owner($link->user);
        $ids = $link->isScoped() ? $link->scope_account_ids : null;

        return SocialAccount::query()
            ->where('user_id', $owner->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when(! $link->isScoped(), fn ($q) => $q->whereIn('id', SocialPostTarget::query()
                ->whereIn('social_post_id', $link->posts()->select('social_posts.id'))
                ->select('social_account_id')))
            ->orderBy('platform')
            ->get(['ulid', 'platform', 'username'])
            ->map(fn (SocialAccount $a) => ['id' => $a->ulid, 'platform' => $a->platform->value, 'handle' => $a->username])
            ->values()
            ->all();
    }

    /**
     * Media as the plan renders it: url, thumbnail, kind and aspect ratio
     * (width / height) when the file is in the library.
     *
     * @param  Collection<int, SocialPost>  $posts
     * @return array<int, list<array{url: string, thumbnail_url: ?string, type: string, aspect: ?float, width: ?int, height: ?int}>>
     */
    public static function media(Collection $posts): array
    {
        $ulids = $posts->flatMap(fn (SocialPost $p) => MediaLinks::ulidsIn($p->media))->unique()->values()->all();
        $files = $ulids === [] ? collect()
            : File::query()->whereIn('ulid', $ulids)->get(['ulid', 'width', 'height', 'mime_type'])->keyBy('ulid');

        $out = [];
        foreach ($posts as $post) {
            /** @var list<array<string, mixed>> $items */
            $items = array_values(array_filter((array) ($post->getAttribute('media') ?? []), 'is_array'));
            $out[$post->id] = array_map(function (array $m) use ($files) {
                $url = is_string($m['url'] ?? null) ? $m['url'] : '';
                $file = ($ulid = MediaLinks::fileUlid($url)) !== null ? $files->get($ulid) : null;
                $w = $file?->width;
                $h = $file?->height;
                $kind = PublishPreflight::kind($url);

                return [
                    'url' => $url,
                    'thumbnail_url' => isset($m['thumbnail_url']) && is_string($m['thumbnail_url']) ? $m['thumbnail_url'] : null,
                    'type' => $kind !== 'unknown' ? $kind : (str_starts_with((string) $file?->mime_type, 'video/') ? 'video' : 'image'),
                    'aspect' => $w && $h ? round($w / $h, 4) : null,
                    'width' => $w,
                    'height' => $h,
                ];
            }, $items);
        }

        return $out;
    }

    /** @return Builder<SocialPost> */
    private static function workspacePosts(SocialApprovalLink $link): Builder
    {
        return SocialPost::query()
            ->whereIn('social_posts.user_id', Workspace::memberIds(Workspace::owner($link->user)))
            ->where(fn ($q) => $q
                ->whereIn('social_posts.status', [SocialPostStatus::Scheduled->value, SocialPostStatus::Published->value])
                ->orWhere(fn ($d) => $d->where('social_posts.status', SocialPostStatus::Draft->value)
                    ->whereNotNull('social_posts.review_requested_at')));
    }

    /**
     * @param  Builder<SocialPost>  $query
     * @param  list<int>|null  $accountIds
     * @return Builder<SocialPost>
     */
    private static function inRange(Builder $query, ?CarbonImmutable $starts, ?CarbonImmutable $ends, ?array $accountIds): Builder
    {
        self::whereWithin($query, $starts, $ends);

        return $query->when($accountIds !== null, fn ($q) => $q->whereHas('targets', fn ($t) => $t->whereIn('social_account_id', $accountIds)));
    }

    /**
     * Inside [starts, ends): by its slot (scheduled_at, UTC) — or, for a
     * post published "now" with no slot, by when it was made (created_at,
     * app-timezone wall time).
     *
     * @template TQuery of Builder<SocialPost>|BelongsToMany<SocialPost, SocialApprovalLink>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereWithin(Builder|BelongsToMany $query, ?CarbonImmutable $starts, ?CarbonImmutable $ends): Builder|BelongsToMany
    {
        $appTz = (string) config('app.timezone');

        return $query->where(fn ($w) => $w
            ->where(fn ($s) => $s->whereNotNull('social_posts.scheduled_at')
                ->when($starts !== null, fn ($q) => $q->where('social_posts.scheduled_at', '>=', UtcDateTime::bind($starts)))
                ->when($ends !== null, fn ($q) => $q->where('social_posts.scheduled_at', '<', UtcDateTime::bind($ends))))
            ->orWhere(fn ($n) => $n->whereNull('social_posts.scheduled_at')
                ->where('social_posts.status', SocialPostStatus::Published->value)
                ->when($starts !== null, fn ($q) => $q->where('social_posts.created_at', '>=', $starts->setTimezone($appTz)->format('Y-m-d H:i:s')))
                ->when($ends !== null, fn ($q) => $q->where('social_posts.created_at', '<', $ends->setTimezone($appTz)->format('Y-m-d H:i:s')))));
    }

    /** When a post sits on the plan: its slot, or when it was made (sent "now"). */
    public static function when(SocialPost $post): ?CarbonImmutable
    {
        $at = $post->scheduled_at ?? ($post->created_at === null ? null : CarbonImmutable::parse($post->created_at));

        return $at === null ? null : CarbonImmutable::instance($at);
    }
}
