<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialCommentResource;
use App\Http\Resources\SocialConversationResource;
use App\Models\SocialComment;
use App\Support\Credits;
use App\Support\Inbox;
use App\Support\InboxStore;
use App\Support\ProductEvents;
use App\Support\SocialProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Comments on the posts of connected accounts (Instagram, Facebook):
 * read them, answer in public, or — Instagram only, once, within 7 days —
 * answer in private, which opens a DM thread.
 */
class InboxCommentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account' => ['sometimes', 'string', 'max:26'],
            'post' => ['sometimes', 'string', 'max:26'],
            'unreplied' => ['sometimes', 'boolean'],
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ]);
        InboxController::firstSync($request->user());

        $rows = SocialComment::query()
            ->whereIn('user_id', Inbox::userIds($request->user()))
            ->with(['account', 'post', 'privateReplyConversation'])
            ->when(isset($validated['account']), fn ($q) => $q->whereHas('account', fn ($a) => $a->where('ulid', $validated['account'])))
            ->when(isset($validated['post']), fn ($q) => $q->whereHas('post', fn ($p) => $p->where('ulid', $validated['post'])))
            ->when(isset($validated['unreplied']), fn ($q) => $request->boolean('unreplied')
                ? $q->whereNull('replied_at')->whereNull('private_replied_at')
                : $q->where(fn ($w) => $w->whereNotNull('replied_at')->orWhereNotNull('private_replied_at')))
            ->when(isset($validated['unread']), fn ($q) => $request->boolean('unread') ? $q->whereNull('read_at') : $q->whereNotNull('read_at'))
            ->orderByDesc('commented_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => SocialCommentResource::collection($rows->getCollection()),
            'meta' => ['next_cursor' => $rows->nextCursor()?->encode()],
        ]);
    }

    /** A public reply, threaded under the comment. */
    public function reply(Request $request, SocialComment $comment, SocialProviders $providers): JsonResponse
    {
        Gate::authorize('reply', $comment);
        $validated = $request->validate(['content' => ['required', 'string', 'max:2200']]);
        $account = $comment->account;

        if ($comment->provider_post_id === null) {
            return response()->json([
                'message' => 'This comment’s post is not known to the provider, so it cannot be answered from here.',
                'code' => 'post_unknown',
            ], 422);
        }

        $paid = Credits::chargeReply($request->user(), 'Comment reply · @'.($comment->author_username ?? 'someone'));

        try {
            $result = $providers->for($account)->replyToComment(
                $comment->provider_post_id,
                $comment->comment_id,
                $validated['content'],
                (string) $account->username,
            );
        } catch (Throwable $e) {
            Credits::returnReply($request->user(), $paid, 'Comment reply not posted');

            return Inbox::error($e, $account, 'comment reply') ?? throw $e;
        }

        InboxStore::rememberOwnComment($result['id'] ?? null);
        $comment->forceFill(['replied_at' => now(), 'read_at' => $comment->read_at ?? now()])->save();
        ProductEvents::replySent($request->user(), 'comment', $comment->network);

        return response()->json(['data' => new SocialCommentResource($comment)]);
    }

    /**
     * The one private DM reply an Instagram comment allows, within 7 days,
     * text only. Opens (or joins) a DM thread with the commenter.
     */
    public function privateReply(Request $request, SocialComment $comment, SocialProviders $providers): JsonResponse
    {
        Gate::authorize('reply', $comment);
        $validated = $request->validate(['content' => ['required', 'string', 'max:1000']]);
        $account = $comment->account;

        if ($comment->network !== 'instagram') {
            return response()->json(['message' => 'Private replies are Instagram only.', 'code' => 'private_reply_unsupported'], 422);
        }

        if ($comment->private_replied_at !== null) {
            return response()->json(['message' => 'This comment already has its private reply.', 'code' => 'already_replied'], 409);
        }

        if (! $comment->canPrivateReplyUntil()?->isFuture()) {
            return response()->json([
                'message' => 'Instagram allows a private reply only within 7 days of the comment.',
                'code' => 'private_reply_window_closed',
            ], 422);
        }

        if ($account->needsReconnectForMessages()) {
            return response()->json([
                'message' => 'Reconnect this Instagram account to allow messages.',
                'code' => 'permission_missing',
                'needs_reconnect' => true,
            ], 403);
        }

        $paid = Credits::chargeReply($request->user(), 'Private reply · @'.($comment->author_username ?? 'someone'));

        try {
            $result = $providers->for($account)->privateReply(
                $account->provider_account_id,
                $comment->comment_id,
                $comment->author_id,
                $validated['content'],
            );
        } catch (Throwable $e) {
            Credits::returnReply($request->user(), $paid, 'Private reply not sent');

            return Inbox::error($e, $account, 'private reply') ?? throw $e;
        }

        $conversation = null;
        if (is_string($result['conversation_id'] ?? null) && $result['conversation_id'] !== '') {
            $remote = (array) ($result['conversation'] ?? []);
            $remote['id'] = $result['conversation_id'];
            $remote['network'] = 'instagram';
            $remote['contact'] = array_filter((array) ($remote['contact'] ?? [])) + array_filter([
                'id' => $comment->author_id,
                'username' => $comment->author_username,
            ]);
            $conversation = InboxStore::upsertConversation($account, $remote);

            if (is_string($result['message_id'] ?? null) && $result['message_id'] !== '') {
                InboxStore::upsertMessage($conversation, [
                    'id' => $result['message_id'],
                    'direction' => 'out',
                    'content' => $validated['content'],
                    'status' => 'pending',
                    'source' => 'api',
                ], countUnread: false);
            }
        }

        $comment->forceFill([
            'private_replied_at' => now(),
            'read_at' => $comment->read_at ?? now(),
            'private_reply_conversation_id' => $conversation?->id,
        ])->save();
        ProductEvents::replySent($request->user(), 'private_reply', $comment->network);

        return response()->json([
            'data' => new SocialCommentResource($comment->load('privateReplyConversation')),
            'conversation' => $conversation === null ? null : new SocialConversationResource($conversation->load('account')),
        ]);
    }

    public function read(SocialComment $comment): JsonResponse
    {
        Gate::authorize('view', $comment);
        $comment->forceFill(['read_at' => $comment->read_at ?? now()])->save();

        return response()->json(['data' => new SocialCommentResource($comment)]);
    }
}
