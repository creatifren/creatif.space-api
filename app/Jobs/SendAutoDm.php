<?php

namespace App\Jobs;

use App\Models\SocialComment;
use App\Support\AutoDm;
use App\Support\SocialProviders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** v10.5: a new comment on a post with an Auto DM rule (AutoDm). Once: the log row is the guard. */
class SendAutoDm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $commentId) {}

    public function handle(SocialProviders $providers): void
    {
        $comment = SocialComment::query()->with('account')->find($this->commentId);
        if ($comment !== null) {
            AutoDm::handle($comment, $providers);
        }
    }
}
