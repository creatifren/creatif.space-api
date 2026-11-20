<?php

namespace App\Jobs;

use App\Enums\SocialPostTargetStatus;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\OutstandService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v10.2 First comment: once a post is live, its First comment goes on as
 * the first comment on each Instagram, Facebook and LinkedIn account it was
 * published to (Outstand replies on the post). Once per account: the target
 * keeps when it went out, or why it did not. Other networks are skipped.
 */
class PostFirstComment implements ShouldQueue
{
    use Queueable;

    public const NETWORKS = ['instagram', 'facebook', 'linkedin'];

    public int $tries = 3;

    public function __construct(public int $postId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(OutstandService $outstand): void
    {
        $post = SocialPost::query()->with('targets.account')->find($this->postId);
        $text = trim((string) $post?->first_comment);
        if ($post === null || $text === '' || $post->is_demo) {
            return;
        }

        foreach ($post->targets as $target) {
            if (! $this->due($target)) {
                continue;
            }
            $account = $target->account;
            $postId = (string) ($target->retry_provider_post_id ?: $post->provider_post_id);
            if ($post->provider !== 'outstand' || $postId === '' || $account === null || (string) $account->username === '') {
                $target->forceFill(['first_comment_error' => 'not_supported'])->save();

                continue;
            }
            try {
                $outstand->commentOnPost($postId, $text, (string) $account->username);
                $target->forceFill(['first_comment_at' => now(), 'first_comment_error' => null])->save();
            } catch (Throwable $e) {
                Log::warning('first_comment.failed', ['post' => $post->ulid, 'target' => $target->id, 'error' => $e->getMessage()]);
                if ($this->attempts() >= $this->tries) {
                    $target->forceFill(['first_comment_error' => mb_substr($e->getMessage(), 0, 191)])->save();
                } else {
                    $this->release($this->backoff()[$this->attempts() - 1] ?? 300);

                    return;
                }
            }
        }
    }

    private function due(SocialPostTarget $target): bool
    {
        return $target->status === SocialPostTargetStatus::Published
            && $target->first_comment_at === null
            && $target->first_comment_error === null
            && in_array($target->account?->platform?->value, self::NETWORKS, true);
    }
}
