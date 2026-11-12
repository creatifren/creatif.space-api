<?php

namespace App\Jobs;

use App\Casts\UtcDateTime;
use App\Enums\SocialPostStatus;
use App\Enums\UserStatus;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\SocialPostMissed;
use App\Support\SocialPostRelay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every five minutes: a scheduled post whose time passed GRACE_MINUTES ago
 * and still reads Scheduled has missed it — held for an approval that never
 * came, or sent but never confirmed. Its owner gets one email (the "Post
 * missed / failed" switch, publish_failed), once per post; a post that then
 * fails sends nothing more (SocialPost::claimFailureNotice).
 *
 * A sent post is first checked with the relay (SocialPostRelay::reconcile),
 * so a late result webhook is not reported as a miss. Only the last
 * LOOKBACK_HOURS are considered: an old stuck post is not news.
 */
class NotifyMissedPosts implements ShouldQueue
{
    use Queueable;

    public const GRACE_MINUTES = 30;

    public const LOOKBACK_HOURS = 24;

    private const BATCH = 200;

    public int $tries = 1;

    public function handle(SocialPostRelay $relay): void
    {
        $missed = self::candidates()->limit(self::BATCH)->get(['id', 'user_id', 'provider_post_id']);

        // Ask the relay first about posts that did go out: the result may just be late.
        $sentBy = $missed->whereNotNull('provider_post_id')->pluck('user_id')->unique();
        foreach (User::query()->whereKey($sentBy)->get() as $user) {
            try {
                $relay->reconcile($user);
            } catch (Throwable $e) {
                Log::warning('missed_posts.reconcile_failed', ['user' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($missed as $row) {
            $post = self::candidates()->with('user')->find($row->id);
            if ($post === null || ! $post->claimFailureNotice()) {
                continue;
            }

            try {
                $post->user->notify(new SocialPostMissed($post));
            } catch (Throwable $e) {
                Log::warning('missed_posts.notify_failed', ['post' => $post->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return Builder<SocialPost>
     */
    private static function candidates(): Builder
    {
        return SocialPost::query()
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNull('failure_notified_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', UtcDateTime::bind(now()->subMinutes(self::GRACE_MINUTES)))
            ->where('scheduled_at', '>', UtcDateTime::bind(now()->subHours(self::LOOKBACK_HOURS)))
            ->whereHas('user', fn ($u) => $u->where('status', UserStatus::Active))
            ->orderBy('scheduled_at');
    }
}
