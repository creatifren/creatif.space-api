<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Support\Credits;
use App\Support\Inbox;
use App\Support\PlanQuota;
use App\Support\ReplyAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AI replies for Engage (backlog #2; Rizal 21 Nov: 100 Credits, Standard
 * model). Pro and Max. Nothing is sent: the user checks each reply and sends
 * it through the normal reply path. Credits come back for any reply the AI
 * could not write.
 *
 * POST /inbox/feed/{item}/suggestions — 3 replies for one thread (item = dm_<ulid> | comment_<ulid>).
 * POST /inbox/feed/drafts {items: [...]} — "Reply all with AI": 1 draft each, up to 20.
 */
class InboxSuggestionController extends Controller
{
    public const MAX_DRAFTS = 20;

    public function __invoke(Request $request, string $item): JsonResponse
    {
        $user = $request->user();
        $row = $this->resolve($request, $item);
        Gate::authorize('reply', $row);
        PlanQuota::requireAiReplySuggestions($user, 'item');

        if ($row->account->is_demo) {
            return response()->json(['message' => 'Sample conversations are not answered.'], 422);
        }
        abort_unless(ReplyAi::ready(), 503, 'AI is not set up.');

        $context = ReplyAi::context($row);
        abort_if($context === '', 422, 'There is nothing to answer yet.');

        $price = Credits::perSuggestion();
        $parts = Credits::chargeAi($user, $price, 'AI reply suggestions');

        $suggestions = ReplyAi::write($context, 3, \App\Support\ReplyStyle::prompt(\App\Support\ReplyStyle::forAccount($row->account)));
        if ($suggestions === []) {
            Credits::returnAi($user, $parts, 'AI reply suggestions · no answer');
            abort(502, 'The AI did not answer — try again. Your Credits were not used.');
        }

        return response()->json(['data' => [
            'suggestions' => $suggestions,
            'credits' => $price,
        ]]);
    }

    public function drafts(Request $request): JsonResponse
    {
        $user = $request->user();
        PlanQuota::requireAiReplySuggestions($user, 'items');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_DRAFTS],
            'items.*' => ['required', 'string', 'distinct', 'max:40'],
        ]);
        abort_unless(ReplyAi::ready(), 503, 'AI is not set up.');

        $rows = [];
        foreach ($data['items'] as $item) {
            $row = $this->resolve($request, $item);
            Gate::authorize('reply', $row);
            if (! $row->account->is_demo && ($context = ReplyAi::context($row)) !== '') {
                $rows[$item] = [$context, \App\Support\ReplyStyle::prompt(\App\Support\ReplyStyle::forAccount($row->account))];
            }
        }
        abort_if($rows === [], 422, 'There is nothing to answer here.');

        $price = Credits::perSuggestion();
        $parts = Credits::chargeAi($user, $price * count($rows), 'Reply all with AI · '.count($rows).' replies', 'items');

        $drafts = [];
        $missed = 0;
        foreach ($rows as $item => [$context, $style]) {
            $reply = ReplyAi::write($context, 1, $style)[0] ?? null;
            if ($reply === null) {
                $missed++;
            }
            $drafts[] = ['item' => $item, 'reply' => $reply];
        }

        if ($missed > 0) {
            // Give back the missed replies, Top-up first (it was taken last).
            $back = $price * $missed;
            $topup = min($back, $parts[1]);
            Credits::returnAi($user, [$back - $topup, $topup], "Reply all with AI · {$missed} not written");
        }

        return response()->json(['data' => [
            'drafts' => $drafts,
            'credits' => $price * (count($rows) - $missed),
        ]]);
    }

    /** dm_<ulid> / comment_<ulid> in this workspace, or 404 (as InboxFeedController). */
    private function resolve(Request $request, string $item): SocialConversation|SocialComment
    {
        $row = null;
        if (preg_match('/^(dm|comment)_([0-9A-Za-z]{26})$/', $item, $m) === 1) {
            $query = $m[1] === 'dm'
                ? Inbox::liveChannels(SocialConversation::query())->with('account')
                : Inbox::liveChannels(SocialComment::query())->with(['account', 'post']);
            $row = $query->whereIn('user_id', Inbox::userIds($request->user()))->where('ulid', $m[2])->first();
        }

        if ($row === null) {
            abort(response()->json(['message' => 'No conversation with that id in this workspace.', 'code' => 'not_found', 'item' => $item], 404));
        }

        return $row;
    }
}
