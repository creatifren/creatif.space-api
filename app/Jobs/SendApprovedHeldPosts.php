<?php

namespace App\Jobs;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Exceptions\AccountReconnectRequired;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PublishPreflightFailed;
use App\Exceptions\ScheduleBeyondHorizon;
use App\Http\Controllers\Api\V1\PublicApprovalController;
use App\Models\SocialPost;
use App\Notifications\SocialPostDecided;
use App\Notifications\SocialPostFailed;
use App\Services\DemoSocialProvider;
use App\Support\ScheduleHorizon;
use App\Support\SocialPostRelay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Posts held because their slot was further out than the relay accepts
 * (SocialPostRelay::heldForLater): approved ones (a client's or the owner's
 * yes) and the owner's own posts scheduled past it (Pro/Max: up to 90
 * days). Each goes to the relay as soon as its slot is inside the horizon,
 * charged then, like any send. One that still cannot go (Credits, a
 * disconnected account, a network refusal):
 * - on an approval link: stays approved and held, its owner is told once
 *   a day per post, and it is tried again;
 * - otherwise (the owner's own): marked Failed with the reason, and the
 *   owner is told once (SocialPostFailed) — it shows in failed posts and
 *   can be tried again from there.
 */
class SendApprovedHeldPosts implements ShouldQueue
{
    use Queueable;

    private const BATCH = 100;

    public int $tries = 1;

    public function handle(SocialPostRelay $relay): void
    {
        $held = SocialPost::query()
            ->real()
            ->where('status', SocialPostStatus::Scheduled)
            ->where(fn ($q) => $q
                ->where('approval', SocialPostApproval::Approved->value)
                ->orWhere(fn ($q) => $q->whereNull('approval')
                    ->where('raw_payload->hold', SocialPostRelay::HORIZON_HOLD['hold'])))
            ->whereNull('provider_post_id')
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at')
            ->limit(self::BATCH)
            ->get(['id', 'provider', 'scheduled_at']);

        foreach ($held as $row) {
            if (ScheduleHorizon::beyond($row->provider, $row->scheduled_at)) {
                continue;
            }

            Cache::lock("social-post:{$row->id}:approve", 120)->get(function () use ($row, $relay): void {
                $post = SocialPost::query()->with('user')->find($row->id);
                if ($post === null || ! SocialPostRelay::heldForLater($post)
                    || ScheduleHorizon::beyond($post->provider, $post->scheduled_at)) {
                    return;
                }

                try {
                    $relay->send($post);
                } catch (ValidationException|AccountReconnectRequired|PublishPreflightFailed|NotEnoughCredits|ScheduleBeyondHorizon $e) {
                    $this->blocked($post, $e->getMessage());
                } catch (Throwable $e) {
                    Log::warning('social.held_send_failed', ['post' => $post->id, 'error' => $e->getMessage()]);
                    // The relay down or busy (no answer, 5xx, 429) on the
                    // owner's own post: tried again on the next run while its
                    // slot is more than an hour away; only then failed.
                    if (self::transient($e) && ! $post->approvalLinks()->exists()
                        && $post->scheduled_at !== null && $post->scheduled_at->greaterThan(now()->addHour())) {
                        return;
                    }
                    $this->blocked($post, PublicApprovalController::refusedBy($post, $e));
                }
            });
        }
    }

    private static function transient(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
    }

    private function blocked(SocialPost $post, string $why): void
    {
        $link = $post->approvalLinks()->latest('social_approval_links.id')->first();
        if ($link === null) {
            $this->failed($post, $why);

            return;
        }
        if (! Cache::add("held-send-blocked:{$post->id}", 1, now()->addDay())) {
            return;
        }

        $link->user->notify(new SocialPostDecided(
            $link, SocialPostDecided::BLOCKED, (string) ($post->approval_by ?? 'Your client'), 1, $post,
            $why.' It stays approved; we try again automatically.',
        ));
    }

    /**
     * The owner's own held post cannot go: Failed, with the reason on each
     * destination, so it shows (failed posts, retry) — never a post that
     * quietly stays "scheduled" past its time. Nothing was charged.
     */
    private function failed(SocialPost $post, string $why): void
    {
        $reason = Str::limit($why, 480);
        DB::transaction(function () use ($post, $reason): void {
            $post->targets()->where('status', SocialPostTargetStatus::Pending)
                ->update(['status' => SocialPostTargetStatus::Failed, 'fail_reason' => $reason]);
            // Out of the hold for good: a later "try again" (retryFailed)
            // sends it itself, so this job must not pick it up again —
            // hence no hold mark and no bare Approved (approval_by/at stay).
            $post->update([
                'status' => SocialPostStatus::Failed,
                'fail_reason' => $reason,
                'raw_payload' => null,
                'approval' => null,
            ]);
        });

        if ($post->provider !== DemoSocialProvider::NAME && $post->claimFailureNotice()) {
            $post->user->notify(new SocialPostFailed($post));
        }
    }
}
