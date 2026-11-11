<?php

namespace App\Jobs;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Exceptions\AccountReconnectRequired;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PublishPreflightFailed;
use App\Exceptions\ScheduleBeyondHorizon;
use App\Http\Controllers\Api\V1\PublicApprovalController;
use App\Models\SocialPost;
use App\Notifications\SocialPostDecided;
use App\Support\ScheduleHorizon;
use App\Support\SocialPostRelay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Posts a client approved while their slot was further out than the relay
 * accepts (SocialPostRelay::heldForLater): each goes to the relay as soon
 * as its slot is inside the horizon, charged then, like any send. One that
 * still cannot go (Credits, a disconnected account, a network refusal)
 * stays approved and held, and its owner is told — once a day per post.
 */
class SendApprovedHeldPosts implements ShouldQueue
{
    use Queueable;

    private const BATCH = 100;

    public int $tries = 1;

    public function handle(SocialPostRelay $relay): void
    {
        $held = SocialPost::query()
            ->where('status', SocialPostStatus::Scheduled)
            ->where('approval', SocialPostApproval::Approved->value)
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
                    $this->blocked($post, PublicApprovalController::refusedBy($post, $e));
                }
            });
        }
    }

    private function blocked(SocialPost $post, string $why): void
    {
        $link = $post->approvalLinks()->latest('social_approval_links.id')->first();
        if ($link === null || ! Cache::add("held-send-blocked:{$post->id}", 1, now()->addDay())) {
            return;
        }

        $link->user->notify(new SocialPostDecided(
            $link, SocialPostDecided::BLOCKED, (string) ($post->approval_by ?? 'Your client'), 1, $post,
            $why.' It stays approved; we try again automatically.',
        ));
    }
}
