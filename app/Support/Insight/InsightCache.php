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
    public const TTL_MINUTES = 15;

    /**
     * QA-10 (25 Nov 2026): an answer with a skipped channel (relay slow or
     * down for one account) is still kept this long, so a reload does not
     * walk every feed again for 20–40 s.
     */
    public const PARTIAL_TTL_MINUTES = 2;

    /** QA-10: summary, best times and top posts load together; one computes, the others wait for it. */
    public const LOCK_SECONDS = 60;

    /**
     * The collector's answer shape: bump it when collect() returns new
     * fields (top_ranked, best_times …), so a deploy never reads an answer
     * cached by the code before it.
     */
    public const SHAPE = 2;

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Closure(): array<string, mixed>  $compute
     * @param  (Closure(array<string, mixed>): bool)|null  $keep  false = do not cache this result
     * @return array<string, mixed>
     */
    public static function remember(User $owner, Collection $accounts, InsightPeriod $period, Closure $compute, ?Closure $keep = null): array
    {
        $key = implode(':', [
            'insight',
            's'.self::SHAPE,
            $owner->id,
            'v'.self::version($owner->id),
            $period->kind,
            $period->from->toDateString(),
            $period->to->toDateString(),
            sha1($accounts->pluck('ulid')->sort()->implode(',')),
        ]);

        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }

        $store = function () use ($key, $compute, $keep): array {
            $again = Cache::get($key);
            if (is_array($again)) {
                return $again;
            }
            $value = $compute();
            $full = $keep === null || $keep($value);
            Cache::put($key, $value, now()->addMinutes($full ? self::TTL_MINUTES : self::PARTIAL_TTL_MINUTES));

            return $value;
        };

        try {
            /** @var array<string, mixed> */
            return Cache::lock($key.':lock', self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $store);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return $store();
        }
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
