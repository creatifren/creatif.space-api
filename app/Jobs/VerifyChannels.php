<?php

namespace App\Jobs;

use App\Exceptions\ChannelCheckUnavailable;
use App\Models\SocialAccount;
use App\Support\ChannelHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every minute, the connected channels whose check is due (ChannelHealth):
 * never checked, connected or reconnected since the last check (so a new
 * connection is checked within a minute or so), or last checked a day ago.
 * At most BATCH per run, so the daily round spreads over the day by itself
 * and the relay never sees a burst. A failed check emails the owner once.
 *
 * The relay not answering says nothing about a channel: that channel waits
 * BACKOFF_MINUTES, and the run stops after MAX_UNAVAILABLE such answers
 * (the relay is down — the next run tries again).
 */
class VerifyChannels implements ShouldQueue
{
    use Queueable;

    public const BATCH = 20;

    public const EVERY_HOURS = 24;

    public const BACKOFF_MINUTES = 60;

    public const MAX_UNAVAILABLE = 3;

    public int $tries = 1;

    public function handle(ChannelHealth $health): void
    {
        $unavailable = 0;

        foreach (self::due() as $account) {
            try {
                $health->check($account);
            } catch (ChannelCheckUnavailable $e) {
                Log::warning('social.channel_check_unavailable', ['account' => $account->ulid, 'error' => $e->getMessage()]);
                Cache::put(self::backoffKey($account), true, now()->addMinutes(self::BACKOFF_MINUTES));
                if (++$unavailable >= self::MAX_UNAVAILABLE) {
                    return;
                }

                continue;
            }

            if ($account->health_status === ChannelHealth::FAILED) {
                $health->notifyOnce($account);
            }
        }
    }

    /**
     * Oldest check first; never-checked ones before all.
     *
     * @return Collection<int, SocialAccount>
     */
    public static function due(): Collection
    {
        return SocialAccount::query()
            ->usable()
            ->whereNull('removed_at')
            ->where(fn ($q) => $q->whereNull('last_verified_at')
                ->orWhereColumn('last_verified_at', '<', 'connected_at')
                ->orWhere('last_verified_at', '<', now()->subHours(self::EVERY_HOURS)))
            ->orderByRaw('case when last_verified_at is null then 0 else 1 end')
            ->orderBy('last_verified_at')
            ->orderBy('id')
            // Room for the ones still backing off.
            ->limit(self::BATCH * 2)
            ->with('user')
            ->get()
            ->reject(fn (SocialAccount $a) => Cache::has(self::backoffKey($a)))
            ->take(self::BATCH)
            ->values();
    }

    private static function backoffKey(SocialAccount $account): string
    {
        return 'channel-check-backoff:'.$account->id;
    }
}
