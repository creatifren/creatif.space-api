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
     * QA-10 (25 Nov 2026): an answer with a skipped channel is not kept for
     * the next reload (QA P1 18 Nov: a later read should be complete), but
     * readers that waited on the same compute get it, instead of each
     * walking every feed again.
     */
    public const PARTIAL_SHARE_SECONDS = 60;

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
    public static function remember(User $owner, Collection $accounts, InsightPeriod $period, Closure $compute, ?Closure $keep = null, ?Closure $warm = null): array
    {
        $version = self::version($owner->id);
        $key = self::key($owner, $accounts, $period, $version);

        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }

        // QA-10 (25 Nov 2026, CS_Builder_31): a complete answer whose 15
        // minutes ran out while no new numbers landed (same version) is
        // answered at once; a queued read brings it up to date behind it.
        $stale = Cache::get(self::staleKey($owner, $accounts, $period));
        if ($warm !== null && is_array($stale) && ($stale['v'] ?? null) === $version && is_array($stale['value'] ?? null)) {
            $warm();

            return $stale['value'];
        }

        $partial = false;
        $value = self::compute($owner, $accounts, $period, $key, $version, $compute, $keep, $partial);

        // A partial answer (time budget ran out): read every channel in the
        // queue without the gateway's limit, so the next load is complete.
        if ($partial && $warm !== null) {
            $warm();
        }

        return $value;
    }

    /**
     * Compute and keep a fresh answer, whatever is cached (the queued warm-up).
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Closure(): array<string, mixed>  $compute
     * @param  (Closure(array<string, mixed>): bool)|null  $keep
     * @return array<string, mixed>
     */
    public static function refresh(User $owner, Collection $accounts, InsightPeriod $period, Closure $compute, ?Closure $keep = null): array
    {
        $version = self::version($owner->id);
        $key = self::key($owner, $accounts, $period, $version);
        Cache::forget($key);
        $partial = false;

        return self::compute($owner, $accounts, $period, $key, $version, $compute, $keep, $partial);
    }

    /** Complete answers are kept this long as the fallback while a fresh one is read. */
    public const STALE_HOURS = 24;

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Closure(): array<string, mixed>  $compute
     * @param  (Closure(array<string, mixed>): bool)|null  $keep
     * @return array<string, mixed>
     */
    private static function compute(User $owner, Collection $accounts, InsightPeriod $period, string $key, int $version, Closure $compute, ?Closure $keep, bool &$partial): array
    {
        $staleKey = self::staleKey($owner, $accounts, $period);
        $t0 = microtime(true);
        $store = function () use ($key, $staleKey, $version, $compute, $keep, $t0, &$partial): array {
            $again = Cache::get($key);
            if (is_array($again)) {
                return $again;
            }
            // Waited behind another reader's compute: take its answer, even partial.
            $shared = microtime(true) - $t0 > 0.2 ? Cache::get($key.':shared') : null;
            if (is_array($shared)) {
                return $shared;
            }
            $value = $compute();
            if ($keep === null || $keep($value)) {
                Cache::put($key, $value, now()->addMinutes(self::TTL_MINUTES));
                Cache::put($staleKey, ['v' => $version, 'value' => $value], now()->addHours(self::STALE_HOURS));
            } else {
                $partial = true;
                Cache::put($key.':shared', $value, now()->addSeconds(self::PARTIAL_SHARE_SECONDS));
            }

            return $value;
        };

        try {
            /** @var array<string, mixed> */
            return Cache::lock($key.':lock', self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $store);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return $store();
        }
    }

    /** @param  Collection<int, SocialAccount>  $accounts */
    private static function key(User $owner, Collection $accounts, InsightPeriod $period, int $version): string
    {
        return implode(':', [
            'insight',
            's'.self::SHAPE,
            $owner->id,
            'v'.$version,
            $period->kind,
            $period->from->toDateString(),
            $period->to->toDateString(),
            sha1($accounts->pluck('ulid')->sort()->implode(',')),
        ]);
    }

    /** @param  Collection<int, SocialAccount>  $accounts */
    private static function staleKey(User $owner, Collection $accounts, InsightPeriod $period): string
    {
        return implode(':', [
            'insight-stale',
            's'.self::SHAPE,
            $owner->id,
            $period->kind,
            $period->from->toDateString(),
            $period->to->toDateString(),
            sha1($accounts->pluck('ulid')->sort()->implode(',')),
        ]);
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
