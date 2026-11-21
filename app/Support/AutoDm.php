<?php

namespace App\Support;

use App\Models\SocialAutoDm;
use App\Models\SocialComment;
use App\Models\SocialPost;
use App\Notifications\AutoDmPaused;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * v10.5 "Auto DM on comment" (Comment-to-DM; Rizal 21 Nov). Per post: a
 * keyword; whoever comments it gets one private DM — Fixed text (100
 * Credits) or Written by AI (300 Credits, Standard model). Pro and Max
 * (feature comment_to_dm). Instagram + Facebook. One DM per person per post
 * (social_auto_dms unique post + commenter). Credits back when the DM fails.
 * Short of Credits: the rule pauses (paused_at) and the owner gets a bell;
 * saving the rule again resumes it.
 */
class AutoDm
{
    public const NETWORKS = ['instagram', 'facebook'];

    public const MODES = ['fixed', 'ai'];

    public static function price(string $mode): int
    {
        return $mode === 'ai'
            ? (int) config('services.inbox.credits_auto_dm_ai', 300)
            : (int) config('services.inbox.credits_auto_dm_fixed', 100);
    }

    /** The keyword appears in the comment as a word (any case); "INFO" matches "info please" and "#info". */
    public static function matches(string $keyword, string $text): bool
    {
        $k = trim(mb_strtolower($keyword));

        return $k !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($k, '/').'(?![\p{L}\p{N}])/u', mb_strtolower($text)) === 1;
    }

    /** Called once for every new comment the Inbox stores (sync or webhook). */
    public static function handle(SocialComment $comment, SocialProviders $providers): ?SocialAutoDm
    {
        $post = $comment->social_post_id ? SocialPost::query()->find($comment->social_post_id) : null;
        $rule = $post?->auto_dm;
        if ($post === null || ! is_array($rule) || ! empty($rule['paused_at'])) {
            return null;
        }
        $account = $comment->account;
        if ($account === null || $account->is_demo || $comment->parent_comment_id !== null
            || ! in_array($comment->network, self::NETWORKS, true)
            || ! self::matches($rule['keyword'], (string) $comment->text)) {
            return null;
        }
        $owner = Workspace::owner($post->user);
        if (! PlanQuota::hasFeature($owner, 'comment_to_dm')) {
            return null;
        }
        $commenter = (string) ($comment->author_id ?: $comment->author_username);
        if ($commenter === '') {
            return null;
        }

        try {
            $row = SocialAutoDm::query()->create([
                'social_post_id' => $post->id,
                'social_comment_id' => $comment->id,
                'user_id' => $owner->id,
                'commenter' => mb_substr($commenter, 0, 191),
                'status' => SocialAutoDm::FAILED,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // this person already got this post's DM
        }

        $mode = $rule['mode'];
        $price = self::price($mode);
        $label = 'Auto DM · @'.($comment->author_username ?? 'someone');

        try {
            $parts = Credits::chargeAi($owner, $price, $label);
        } catch (ValidationException) {
            $row->forceFill(['status' => SocialAutoDm::NO_CREDITS])->save();
            $post->forceFill(['auto_dm' => [...$rule, 'paused_at' => now()->toIso8601String()]])->save();
            $owner->notify(new AutoDmPaused($post->ulid, $price));

            return $row;
        }

        $text = $mode === 'ai'
            ? ReplyAi::dm($rule['message'], ReplyAi::context($comment))
            : trim($rule['message']);

        $error = null;
        if ($text === null || $text === '') {
            $error = 'ai_no_answer';
        } else {
            try {
                $providers->for($account)->privateReply($account->provider_account_id, $comment->comment_id, $comment->author_id, $text);
            } catch (Throwable $e) {
                Log::info('auto dm: not sent', ['post' => $post->id, 'comment' => $comment->id, 'error' => $e->getMessage()]);
                $error = mb_substr(class_basename($e).': '.$e->getMessage(), 0, 191);
            }
        }

        if ($error !== null) {
            Credits::returnAi($owner, $parts, $label.' · not sent');
            $row->forceFill(['status' => SocialAutoDm::FAILED, 'error' => $error])->save();

            return $row;
        }

        $row->forceFill(['status' => SocialAutoDm::SENT, 'credits' => $price])->save();
        if ($comment->network === 'instagram') {
            $comment->forceFill(['private_replied_at' => now()])->save();
        }

        return $row;
    }
}
