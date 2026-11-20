<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Support\Credits;
use App\Support\Inbox;
use App\Support\PlanQuota;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * AI reply suggestions (backlog #2, Rizal 20 Nov): Pro and Max, 300 Credits
 * per tap (services.inbox.credits_per_suggestion), three short replies to
 * a DM thread or a comment. Nothing is sent: the user picks one, edits it
 * and replies through the normal reply path. An AI failure gives the
 * Credits back. Same Claude setup as the caption assist (services.ai).
 *
 * POST /inbox/feed/{item}/suggestions — item = dm_<ulid> | comment_<ulid>.
 */
class InboxSuggestionController extends Controller
{
    private const ANTHROPIC_VERSION = '2023-06-01';

    private const COUNT = 3;

    private const PROMPT = 'You help a social media manager answer their audience on behalf of a brand. '
        .'Write exactly 3 different short replies to the last message from the other person, in the language they wrote in. '
        .'Friendly, natural, no hashtags, no made-up facts, prices or promises. '
        .'Reply with the 3 replies only, one per line, without numbers or quotes.';

    public function __invoke(Request $request, string $item): JsonResponse
    {
        $user = $request->user();
        $row = $this->resolve($request, $item);
        Gate::authorize('reply', $row);
        PlanQuota::requireAiReplySuggestions($user, 'item');

        if ($row->account->is_demo) {
            return response()->json(['message' => 'Sample conversations are not answered.'], 422);
        }

        $config = config('services.ai');
        abort_unless($config['key'] && $config['base_url'] && $config['model'], 503, 'AI is not set up.');

        $context = $this->context($row);
        abort_if($context === '', 422, 'There is nothing to answer yet.');

        $price = Credits::perSuggestion();
        $parts = Credits::chargeAi($user, $price, 'AI reply suggestions');

        $suggestions = [];
        try {
            $response = Http::withHeaders([
                'x-api-key' => $config['key'],
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
                ->timeout(30)
                ->post(rtrim($config['base_url'], '/').'/messages', [
                    'model' => $config['model'],
                    'max_tokens' => 600,
                    'system' => self::PROMPT,
                    'messages' => [['role' => 'user', 'content' => $context]],
                ]);

            if ($response->successful()) {
                $text = collect((array) $response->json('content'))->where('type', 'text')->pluck('text')->implode('');
                $suggestions = collect(preg_split('/\R+/u', $text) ?: [])
                    ->map(fn ($l) => trim((string) preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', (string) $l), " \t\"'“”"))
                    ->filter()
                    ->take(self::COUNT)
                    ->values()
                    ->all();
            }
        } catch (ConnectionException) {
            $suggestions = [];
        }

        if ($suggestions === []) {
            Credits::returnAi($user, $parts, 'AI reply suggestions · no answer');
            abort(502, 'The AI did not answer — try again. Your Credits were not used.');
        }

        return response()->json(['data' => [
            'suggestions' => $suggestions,
            'credits' => $price,
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
            abort(response()->json(['message' => 'No conversation with that id in this workspace.', 'code' => 'not_found'], 404));
        }

        return $row;
    }

    /** The thread as plain lines: the last 10 DMs, or the post caption + the comment. */
    private function context(SocialConversation|SocialComment $row): string
    {
        if ($row instanceof SocialConversation) {
            return $row->messages()->latest('id')->limit(10)->get(['direction', 'content'])
                ->reverse()
                ->filter(fn (SocialMessage $m) => trim((string) $m->content) !== '')
                ->map(fn (SocialMessage $m) => ($m->direction === SocialMessage::OUT ? 'Brand: ' : 'Them: ').mb_substr((string) $m->content, 0, 1000))
                ->implode("\n");
        }

        $text = trim((string) $row->text);
        if ($text === '') {
            return '';
        }
        $caption = trim((string) ($row->post?->caption ?? ''));

        return ($caption !== '' ? 'Brand post: '.mb_substr($caption, 0, 1000)."\n" : '').'Them (comment): '.mb_substr($text, 0, 1000);
    }
}
