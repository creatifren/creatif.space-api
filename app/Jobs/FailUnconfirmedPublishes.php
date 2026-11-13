<?php

namespace App\Jobs;

use App\Casts\UtcDateTime;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Support\SocialPostRelay;
use App\Support\SocialProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A post sent to the relay whose destination is still pending AFTER_MINUTES
 * after it was due — "now" (sent, no slot) or its slot — is not left silent
 * (live, 13 Nov: a Bluesky post the relay took but never published sat
 * "pending" with no error). The relay is asked first, so a late result is
 * applied, not overruled. What is still pending then:
 *
 * - if nothing on the post has gone out, the relay post is cancelled, so it
 *   cannot go out late after we have said it failed (a failed cancel is
 *   tried again next run, nothing is marked);
 * - each pending destination fails through the normal result path with the
 *   reason `not_confirmed`: its Credits come back, the post rolls up to
 *   Failed (or Published if another destination went out) and its owner
 *   gets the one failure email.
 *
 * Destinations waiting out a platform limit (retry_at) or being retried
 * (retry_provider_post_id) have their own clock and are left alone. Only
 * the last LOOKBACK_HOURS are looked at.
 */
class FailUnconfirmedPublishes implements ShouldQueue
{
    use Queueable;

    public const AFTER_MINUTES = 5;

    public const LOOKBACK_HOURS = 24;

    private const BATCH = 100;

    /** What applyResult classifies as `not_confirmed` (PublishFailure). */
    public const MARKER = '[creatif:not_confirmed] the relay did not confirm the post in time';

    public int $tries = 1;

    public function handle(SocialPostRelay $relay, SocialProviders $providers): void
    {
        $stale = self::candidates()->limit(self::BATCH)->get(['id', 'user_id']);
        if ($stale->isEmpty()) {
            return;
        }

        // A result may only be late: take the relay's word first.
        foreach (User::query()->whereKey($stale->pluck('user_id')->unique())->get() as $user) {
            try {
                $relay->reconcile($user);
            } catch (Throwable $e) {
                Log::warning('unconfirmed_publishes.reconcile_failed', ['user' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($stale as $row) {
            $post = self::candidates()->with(['targets.account', 'user'])->find($row->id);
            if ($post === null) {
                continue;
            }

            $pending = $post->targets->filter(fn (SocialPostTarget $t) => self::isWaiting($t));
            $nothingOut = $post->targets->every(fn (SocialPostTarget $t) => $t->status === SocialPostTargetStatus::Pending);

            if ($nothingOut) {
                try {
                    $providers->for($post)->deletePost((string) $post->provider_post_id);
                } catch (Throwable $e) {
                    Log::warning('unconfirmed_publishes.cancel_failed', ['post' => $post->id, 'error' => $e->getMessage()]);

                    continue;
                }
            }

            foreach ($pending as $target) {
                if ($target->account === null) {
                    continue;
                }
                SocialPostRelay::applyResult($post, $target->account, [
                    'success' => false,
                    'post_id' => (string) $post->provider_post_id,
                    'error' => self::MARKER,
                ], 'job');
            }

            Log::warning('unconfirmed_publishes.failed', ['post' => $post->id, 'destinations' => $pending->count(), 'cancelled' => $nothingOut]);
        }
    }

    private static function isWaiting(SocialPostTarget $t): bool
    {
        return $t->status === SocialPostTargetStatus::Pending && $t->retry_at === null && $t->retry_provider_post_id === null;
    }

    /**
     * Sent posts still Scheduled with a destination pending past its due
     * time: "now" posts by when they were sent (the row is not touched after
     * the send until a result comes), slotted posts by their slot.
     *
     * @return Builder<SocialPost>
     */
    private static function candidates(): Builder
    {
        $due = now()->subMinutes(self::AFTER_MINUTES);

        return SocialPost::query()
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNotNull('provider_post_id')
            ->where('updated_at', '>', now()->subHours(self::LOOKBACK_HOURS))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $now) => $now->whereNull('scheduled_at')->where('updated_at', '<=', $due))
                ->orWhere(fn (Builder $slot) => $slot->whereNotNull('scheduled_at')->where('scheduled_at', '<=', UtcDateTime::bind($due))))
            ->whereHas('targets', fn ($t) => $t->where('status', SocialPostTargetStatus::Pending)
                ->whereNull('retry_at')
                ->whereNull('retry_provider_post_id'));
    }
}
