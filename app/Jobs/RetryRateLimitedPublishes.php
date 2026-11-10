<?php

namespace App\Jobs;

use App\Enums\SocialPostTargetStatus;
use App\Models\SocialPostTarget;
use App\Support\SocialPostRelay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Destinations a platform rate-limited (or briefly could not take) wait
 * with a retry_at instead of failing; this sends each again once its wait
 * is over. Each is locked on its own so two runs never send one twice.
 * A create the provider refuses outright counts as an attempt; past
 * SocialPostRelay::MAX_RETRIES the destination fails for good, and its
 * publish is paid back as on any failure.
 */
class RetryRateLimitedPublishes implements ShouldQueue
{
    use Queueable;

    private const BATCH = 50;

    public int $tries = 1;

    public function handle(SocialPostRelay $relay): void
    {
        $due = SocialPostTarget::query()
            ->where('status', SocialPostTargetStatus::Pending)
            ->whereNotNull('retry_at')
            ->where('retry_at', '<=', now())
            ->orderBy('retry_at')
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($due as $id) {
            Cache::lock('publish-retry:'.$id, 120)->get(function () use ($id, $relay): void {
                $target = SocialPostTarget::query()->find($id);

                // Re-read under the lock: settled or rescheduled meanwhile.
                if ($target === null || $target->status !== SocialPostTargetStatus::Pending
                    || $target->retry_at === null || $target->retry_at->isFuture()) {
                    return;
                }

                try {
                    $relay->retryTarget($target);
                } catch (Throwable $e) {
                    Log::warning('social.publish_retry_failed', [
                        'target' => $target->id,
                        'attempts' => $target->attempts,
                        'error' => $e->getMessage(),
                    ]);

                    $this->refused($target, $e);
                }
            });
        }
    }

    /**
     * The provider would not even take the retry: another attempt later,
     * or — out of attempts — a failed destination with its publish returned.
     */
    private function refused(SocialPostTarget $target, Throwable $e): void
    {
        $target->refresh();
        $attempts = $target->attempts + 1;

        if ($attempts <= SocialPostRelay::MAX_RETRIES - 1) {
            $target->update([
                'attempts' => $attempts,
                'retry_at' => SocialPostRelay::retryAt((string) $target->fail_code, $attempts, null),
            ]);

            return;
        }

        $post = $target->post()->firstOrFail();
        $account = $target->account()->firstOrFail();
        $target->update(['attempts' => SocialPostRelay::MAX_RETRIES]);

        SocialPostRelay::applyResult($post, $account, [
            'success' => false,
            'error' => $target->provider_error ?? 'Rate limited',
            'post_id' => $target->retry_provider_post_id,
        ], 'job');
    }
}
