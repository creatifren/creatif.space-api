<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AccountReconnectRequired;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialCommentResource;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\User;
use App\Support\Credits;
use App\Support\Inbox;
use App\Support\SocialProviders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Engage as one list — comments and DMs together, newest first — for
 * connected apps (MCP list_conversations / reply_to_conversation).
 *
 * Nothing new is decided here. Rows, ownership and replies are the app's
 * Engage endpoints': the same Inbox::userIds() reach, the same policies
 * (a viewer seat reads but does not reply), and a reply goes through
 * InboxController::send / InboxCommentController::reply unchanged — the
 * same Credits, relay call and readable relay errors.
 *
 * Plan: Engage is on every plan (PlanSeeder v2 has no Engage flag; only
 * ai_reply_suggestions and comment_to_dm are Pro/Max), so these are not
 * gated either. When the app gates Engage, gate here the same way.
 *
 * Ids are typed: `dm_<ulid>` (a DM thread) or `comment_<ulid>` (a comment).
 * Another workspace's id answers 404, exactly as a missing one does.
 */
class InboxFeedController extends Controller
{
    public const DM = 'dm';

    public const COMMENT = 'comment';

    /** Sort rank at equal times: DMs before comments. */
    private const RANK = [self::DM => 1, self::COMMENT => 0];

    private const DM_AT = 'COALESCE(last_message_at, created_at)';

    private const COMMENT_AT = 'COALESCE(commented_at, created_at)';

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account' => ['sometimes', 'string', 'max:26'],
            'type' => ['sometimes', Rule::in([self::DM, self::COMMENT])],
            'unread' => ['sometimes', 'boolean'],
            'since' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $user = $request->user();
        // Listing is opening Engage: the same refresh the app does.
        InboxController::firstSync($user);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $cursor = isset($validated['cursor']) ? self::decodeCursor($validated['cursor']) : null;
        $since = isset($validated['since'])
            ? CarbonImmutable::parse($validated['since'])->utc()->format('Y-m-d H:i:s')
            : null;
        $unread = isset($validated['unread']) ? $request->boolean('unread') : null;
        $type = $validated['type'] ?? null;
        $ids = Inbox::userIds($user);

        $filter = function (Builder $q, string $at, string $kind) use ($validated, $since, $cursor, $ids): void {
            Inbox::liveChannels($q)->whereIn('user_id', $ids)
                ->with('account')
                ->select('*')
                ->selectRaw("{$at} as feed_at")
                ->when(isset($validated['account']), fn ($q) => $q->whereHas('account', fn ($a) => $a->where('ulid', $validated['account'])))
                ->when($since !== null, fn ($q) => $q->whereRaw("{$at} >= ?", [$since]))
                ->when($cursor !== null, fn ($q) => self::after($q, $at, self::RANK[$kind], $cursor))
                ->orderByRaw("{$at} desc")
                ->orderByDesc('id');
        };

        /** @var list<array{at: string, rank: int, id: int, row: SocialConversation|SocialComment}> $rows */
        $rows = [];

        if ($type !== self::COMMENT) {
            $q = SocialConversation::query();
            $filter($q, self::DM_AT, self::DM);
            $q->when($unread !== null, fn ($q) => $unread ? $q->where('unread_count', '>', 0) : $q->where('unread_count', 0));
            foreach ($q->limit($perPage + 1)->get() as $c) {
                $rows[] = ['at' => (string) $c->getAttribute('feed_at'), 'rank' => self::RANK[self::DM], 'id' => $c->id, 'row' => $c];
            }
        }

        if ($type !== self::DM) {
            $q = SocialComment::query();
            $filter($q, self::COMMENT_AT, self::COMMENT);
            $q->with(['post', 'privateReplyConversation'])
                ->when($unread !== null, fn ($q) => $unread ? $q->whereNull('read_at') : $q->whereNotNull('read_at'));
            foreach ($q->limit($perPage + 1)->get() as $c) {
                $rows[] = ['at' => (string) $c->getAttribute('feed_at'), 'rank' => self::RANK[self::COMMENT], 'id' => $c->id, 'row' => $c];
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['at'], $b['rank'], $b['id']] <=> [$a['at'], $a['rank'], $a['id']]);
        $page = array_slice($rows, 0, $perPage);
        $last = $page === [] ? null : $page[array_key_last($page)];
        $more = count($rows) > $perPage;

        return response()->json([
            'data' => array_map(fn (array $r) => $this->item($request, $r['row']), $page),
            'meta' => ['next_cursor' => $more && $last !== null ? self::encodeCursor($last) : null],
        ]);
    }

    /**
     * One item and what a reply to it would be: allowed or not (and why),
     * its Credit cost and the longest text it takes. Reads only — this is
     * the preview reply_to_conversation shows before anything is sent.
     */
    public function show(Request $request, string $item): JsonResponse
    {
        $row = $this->resolve($request->user(), $item);
        Gate::authorize('view', $row);

        return response()->json(['data' => $this->item($request, $row) + ['reply' => $this->replyCheck($request->user(), $row)]]);
    }

    /**
     * Send the reply: a DM into the thread, or a public reply under the
     * comment — through the app's own endpoints, unchanged.
     */
    public function reply(Request $request, string $item, SocialProviders $providers): JsonResponse
    {
        $row = $this->resolve($request->user(), $item);
        $request->validate([
            'content' => ['required', 'string', 'max:'.($row instanceof SocialConversation ? 1000 : 2200)],
            // Text only from here; images stay in the app.
            'file_ids' => ['prohibited'],
        ]);

        return $row instanceof SocialConversation
            ? app(InboxController::class)->send($request, $row, $providers)
            : app(InboxCommentController::class)->reply($request, $row, $providers);
    }

    /** dm_<ulid> / comment_<ulid> in this workspace, or 404. */
    private function resolve(User $user, string $item): SocialConversation|SocialComment
    {
        $row = null;
        if (preg_match('/^(dm|comment)_([0-9A-Za-z]{26})$/', $item, $m) === 1) {
            // Only channels that are not disconnected (P1), applied per model for the types.
            $query = $m[1] === self::DM
                ? Inbox::liveChannels(SocialConversation::query())->with('account')
                : Inbox::liveChannels(SocialComment::query())->with(['account', 'post', 'privateReplyConversation']);
            $row = $query->whereIn('user_id', Inbox::userIds($user))->where('ulid', $m[2])->first();
        }

        if ($row === null) {
            abort(response()->json([
                'message' => 'No conversation with that id in this workspace.',
                'code' => 'not_found',
            ], 404));
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function item(Request $request, SocialConversation|SocialComment $row): array
    {
        $channel = [
            'id' => $row->account->ulid,
            'platform' => $row->account->platform,
            'username' => $row->account->username,
        ];

        if ($row instanceof SocialConversation) {
            return [
                'id' => self::DM.'_'.$row->ulid,
                'type' => self::DM,
                'channel' => $channel,
                'author' => [
                    'name' => $row->contact_name ?? $row->contact_username,
                    'username' => $row->contact_username,
                ],
                'last_message' => $row->last_message_preview,
                'time' => $row->last_message_at ?? $row->created_at,
                'unread' => $row->unread_count > 0,
                'post_url' => null,
                'replied' => null,
            ];
        }

        $resource = (new SocialCommentResource($row))->resolve($request);

        return [
            'id' => self::COMMENT.'_'.$row->ulid,
            'type' => self::COMMENT,
            'channel' => $channel,
            'author' => [
                'name' => $row->author_username,
                'username' => $row->author_username,
            ],
            'last_message' => $row->text,
            'time' => $row->commented_at ?? $row->created_at,
            'unread' => $row->read_at === null,
            'post_url' => $resource['post_url'],
            'replied' => $row->replied_at !== null || $row->private_replied_at !== null,
        ];
    }

    /**
     * The same refusals the reply endpoints give, checked without sending.
     *
     * @return array<string, mixed>
     */
    private function replyCheck(User $user, SocialConversation|SocialComment $row): array
    {
        $account = $row->account;
        $dm = $row instanceof SocialConversation;
        $cost = Credits::perReply();
        $balance = $cost > 0 ? Credits::total($user) : null;

        $blocked = match (true) {
            ! Gate::forUser($user)->allows('reply', $row) => [
                'code' => 'forbidden',
                'message' => 'This seat can read Engage but not reply. The workspace owner or an editor can reply.',
            ],
            ! $account->isUsable() => [
                'code' => AccountReconnectRequired::CODE,
                'message' => (new AccountReconnectRequired([$account]))->getMessage(),
            ],
            $row instanceof SocialConversation && $account->needsReconnectForMessages() => [
                'code' => 'permission_missing',
                'message' => 'Reconnect this Instagram account to allow messages.',
            ],
            $row instanceof SocialConversation && ! $row->canReply() => [
                'code' => 'reply_window_closed',
                'message' => 'Instagram only allows a reply within 24 hours of their last message.',
            ],
            $row instanceof SocialComment && $row->provider_post_id === null => [
                'code' => 'post_unknown',
                'message' => 'This comment’s post is not known to the provider, so it cannot be answered from here.',
            ],
            $balance !== null && $balance < $cost => [
                'code' => 'insufficient_credits',
                'message' => "A reply costs {$cost} Credits and the balance has {$balance} — top up to reply.",
            ],
            default => null,
        };

        return [
            'can_reply' => $blocked === null,
            'blocked' => $blocked,
            // A DM goes into the thread; a comment gets a public reply under it.
            'kind' => $dm ? 'direct_message' : 'public_comment_reply',
            'max_length' => $dm ? 1000 : 2200,
            'credit_cost' => $cost,
            'credits_balance' => $balance,
            'can_reply_until' => $row instanceof SocialConversation ? $row->canReplyUntil() : null,
        ];
    }

    /**
     * Rows strictly after the cursor in (time desc, rank desc, id desc).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @param  array{0: string, 1: int, 2: int}  $cursor
     */
    private static function after(Builder $q, string $at, int $rank, array $cursor): void
    {
        [$cAt, $cRank, $cId] = $cursor;

        $q->where(function (Builder $w) use ($at, $rank, $cAt, $cRank, $cId) {
            $w->whereRaw("{$at} < ?", [$cAt]);
            if ($rank < $cRank) {
                $w->orWhereRaw("{$at} = ?", [$cAt]);
            } elseif ($rank === $cRank) {
                $w->orWhere(fn (Builder $x) => $x->whereRaw("{$at} = ?", [$cAt])->where('id', '<', $cId));
            }
        });
    }

    /** @param  array{at: string, rank: int, id: int}  $last */
    private static function encodeCursor(array $last): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([$last['at'], $last['rank'], $last['id']])), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int, 2: int} */
    private static function decodeCursor(string $cursor): array
    {
        $raw = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($raw) || count($raw) !== 3
            || ! is_string($raw[0] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $raw[0]) !== 1
            || ! in_array($raw[1] ?? null, [0, 1], true)
            || ! is_int($raw[2] ?? null)) {
            throw ValidationException::withMessages(['cursor' => 'That cursor is not valid. Start again without one.']);
        }

        return [$raw[0], $raw[1], $raw[2]];
    }
}
