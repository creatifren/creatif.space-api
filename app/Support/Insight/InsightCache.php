<?php

namespace App\Support\Insight;

use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Support\Workspace;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The Insight query cache: one collected answer per workspace + period +
 * channels, kept TTL_MINUTES. Every reader of the numbers goes through it —
 * get_insight_summary, the shared report page, the PDF export and the
 * queued report — so a client opening the page twice, or an agent asking
 * again, never walks every channel's feed at the relay twice.
 *
 * New metrics retire a workspace's answers at once: each key carries the
 * workspace's version, and anything that changes a number bumps it — a
 * follower reading, a post going out, an approval decided (see register()).
 * Network numbers that move on their own (likes on an old post) are at most
 * TTL_MINUTES stale.
 */
final class InsightCache
{
    public const TTL_MINUTES = 5;

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public static function remember(User $owner, Collection $accounts, InsightPeriod $period, Closure $compute): array
    {
        $key = implode(':', [
            'insight',
            $owner->id,
            'v'.self::version($owner->id),
            $period->kind,
            $period->from->toDateString(),
            $period->to->toDateString(),
            sha1($accounts->pluck('ulid')->sort()->implode(',')),
        ]);

        return Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), $compute);
    }

    public static function version(int $ownerId): int
    {
        return (int) Cache::get(self::versionKey($ownerId), 0);
    }

    /** Retire every cached answer of this workspace. */
    public static function bump(int $ownerId): void
    {
        $key = self::versionKey($ownerId);
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key);
    }

    /** Retire the answers of the workspace a user works in. */
    public static function bumpFor(?int $userId): void
    {
        $user = $userId === null ? null : User::query()->find($userId);
        if ($user !== null) {
            self::bump(Workspace::owner($user)->id);
        }
    }

    /**
     * Wire the invalidation to the rows that carry new numbers. Called once
     * from AppServiceProvider::boot().
     */
    public static function register(): void
    {
        // A follower reading (SnapshotFollowerCounts / RefreshFollowerCount).
        FollowerSnapshot::saved(fn (FollowerSnapshot $s) => self::bumpFor(
            SocialAccount::query()->whereKey($s->social_account_id)->value('user_id'),
        ));

        // A post went out (or failed) on a channel: posts, on-time.
        SocialPostTarget::saved(function (SocialPostTarget $t): void {
            if ($t->wasChanged('status') || $t->wasRecentlyCreated) {
                self::bumpFor(SocialPost::query()->whereKey($t->social_post_id)->value('user_id'));
            }
        });

        // An approval decided, or a post scheduled / published: workflow.
        SocialPost::saved(function (SocialPost $p): void {
            if ($p->wasRecentlyCreated || $p->wasChanged(['approval', 'approval_at', 'status', 'scheduled_at'])) {
                self::bumpFor($p->user_id);
            }
        });
    }

    private static function versionKey(int $ownerId): string
    {
        return 'insight-version:'.$ownerId;
    }
}
