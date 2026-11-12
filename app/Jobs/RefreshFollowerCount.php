<?php

namespace App\Jobs;

use App\Contracts\SocialProvider;
use App\Enums\SocialPlatform;
use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One account's follower count from the relay, cached on the row. Queued:
 * the account list never waits on Outstand. At most every STALE_HOURS per
 * account, whether the call worked or not — a failing relay is asked again
 * next window, and the last good count stays.
 */
class RefreshFollowerCount implements ShouldQueue
{
    use Queueable;

    public const STALE_HOURS = 6;

    /**
     * Not asked: Outstand reads up to 500 of the account's own posts for an
     * X metrics call (~$2.50 each on a busy account). Null on purpose.
     */
    public const SKIPPED = [SocialPlatform::X];

    public int $tries = 1;

    public function __construct(public int $accountId) {}

    /** Queue a refresh for each of the user's usable accounts that is due. */
    public static function queueStale(User $user): void
    {
        try {
            $user->socialAccounts()
                ->usable()
                ->where(fn ($q) => $q->whereNull('followers_synced_at')
                    ->orWhere('followers_synced_at', '<', now()->subHours(self::STALE_HOURS)))
                ->pluck('id')
                ->each(function (int $id) {
                    // One queued refresh per account per window, however
                    // often Channels is opened before the worker gets to it.
                    if (Cache::add('followers-refresh:'.$id, 1, now()->addHours(self::STALE_HOURS))) {
                        self::dispatch($id);
                    }
                });
        } catch (Throwable $e) {
            Log::warning('social.followers_queue_failed', ['user' => $user->ulid, 'error' => $e->getMessage()]);
        }
    }

    public function handle(SocialProvider $provider): void
    {
        $account = SocialAccount::query()->find($this->accountId);
        if ($account === null || ! $account->isUsable()) {
            return;
        }
        if ($account->followers_synced_at !== null
            && $account->followers_synced_at->gt(now()->subHours(self::STALE_HOURS))) {
            return;
        }

        $fields = ['followers_synced_at' => now()];

        if (in_array($account->platform, self::SKIPPED, true)) {
            $fields['followers_count'] = null;
        } else {
            try {
                $fields['followers_count'] = $provider->followersCount($account->provider_account_id);
            } catch (Throwable $e) {
                Log::warning('social.followers_failed', ['account' => $account->ulid, 'error' => $e->getMessage()]);
            }
        }

        $account->forceFill($fields)->save();

        // The relay only knows "now": keep one number a day so an Insight
        // report can say where a period started.
        if (isset($fields['followers_count'])) {
            FollowerSnapshot::record($account, $fields['followers_count']);
        }
    }
}
