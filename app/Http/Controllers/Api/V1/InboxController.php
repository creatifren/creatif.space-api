<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPlatform;
use App\Exceptions\AccountReconnectRequired;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialConversationResource;
use App\Http\Resources\SocialMessageResource;
use App\Jobs\SyncInbox;
use App\Models\File;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\User;
use App\Support\Credits;
use App\Support\Inbox;
use App\Support\InboxStore;
use App\Support\ProductEvents;
use App\Support\SocialProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Inbox's DMs (Instagram, through Outstand). Rows are filed by the
 * webhooks and the sync job; this reads them, and sends through the
 * provider. Owner and team see; viewers do not reply (policies).
 */
class InboxController extends Controller
{
    /** Opening Engage re-syncs an account last synced longer ago than this. */
    public const REFRESH_AFTER_MINUTES = 10;

    /** Messages per page of a thread. */
    private const MESSAGES = 50;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account' => ['sometimes', 'string', 'max:26'],
            'status' => ['sometimes', Rule::in([SocialConversation::OPEN, SocialConversation::DONE])],
            'unread' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ]);
        $user = $request->user();
        self::firstSync($user);

        $rows = SocialConversation::query()
            ->whereIn('user_id', Inbox::userIds($user))
            ->with('account')
            ->when(isset($validated['account']), fn ($q) => $q->whereHas('account', fn ($a) => $a->where('ulid', $validated['account'])))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['unread']), fn ($q) => $request->boolean('unread')
                ? $q->where('unread_count', '>', 0)
                : $q->where('unread_count', 0))
            ->when(($validated['q'] ?? '') !== '', function ($q) use ($validated) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $validated['q']).'%';
                $q->where(fn ($w) => $w->where('contact_username', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('last_message_preview', 'like', $like));
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => SocialConversationResource::collection($rows->getCollection()),
            'meta' => ['next_cursor' => $rows->nextCursor()?->encode()],
        ]);
    }

    /**
     * One thread with its newest messages, oldest first. `before` (a
     * message id) pages back. A thread opened for the first time reads its
     * messages from the provider first.
     */
    public function show(Request $request, SocialConversation $conversation, SocialProviders $providers): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $validated = $request->validate(['before' => ['sometimes', 'string', 'max:26']]);

        if ($conversation->messages_synced_at === null) {
            try {
                SyncInbox::syncMessages($providers->for($conversation->account), $conversation);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $before = isset($validated['before'])
            ? $conversation->messages()->where('ulid', $validated['before'])->first()
            : null;

        $messages = $conversation->messages()
            ->when($before !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('created_at', '<', $before->created_at)
                ->orWhere(fn ($x) => $x->where('created_at', $before->created_at)->where('id', '<', $before->id))))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES + 1)
            ->get();
        $more = $messages->count() > self::MESSAGES;
        $messages = $messages->take(self::MESSAGES)->reverse()->values();

        return response()->json([
            'data' => (new SocialConversationResource($conversation->load('account')))->resolve($request)
                + ['messages' => SocialMessageResource::collection($messages)->resolve($request)],
            'meta' => [
                'has_more_messages' => $more,
                'messages_before' => $more ? $messages->first()?->ulid : null,
            ],
        ]);
    }

    /**
     * Reply. Text and/or up to 10 images from the library (sent as their
     * signed asset URLs). Refused here, before the provider, when the 24h
     * window is closed or the account lacks the messages scope. 202: the
     * message is `pending` until the provider's webhook says sent/failed.
     */
    public function send(Request $request, SocialConversation $conversation, SocialProviders $providers): JsonResponse
    {
        Gate::authorize('reply', $conversation);
        $validated = $request->validate([
            'content' => ['required_without:file_ids', 'nullable', 'string', 'max:1000'],
            'file_ids' => ['sometimes', 'array', 'max:10'],
            'file_ids.*' => ['string', 'max:26', 'distinct'],
        ]);
        $account = $conversation->account;
        AccountReconnectRequired::unlessUsable([$account], 'content');

        if ($account->needsReconnectForMessages()) {
            return response()->json([
                'message' => 'Reconnect this Instagram account to allow messages.',
                'code' => 'permission_missing',
                'needs_reconnect' => true,
            ], 403);
        }

        if (! $conversation->canReply()) {
            return response()->json([
                'message' => 'Instagram only allows a reply within 24 hours of their last message.',
                'code' => 'reply_window_closed',
                'can_reply_until' => $conversation->canReplyUntil(),
            ], 422);
        }

        $media = $this->mediaUrls($request, $validated['file_ids'] ?? []);
        $content = isset($validated['content']) && trim($validated['content']) !== '' ? $validated['content'] : null;

        if ($content === null && $media === []) {
            throw ValidationException::withMessages(['content' => 'Write a message or attach an image.']);
        }

        $paid = Credits::chargeReply($request->user(), 'Inbox reply · @'.($conversation->contact_username ?? 'contact'));

        try {
            $result = $providers->for($account)->sendMessage($conversation->provider_conversation_id, [
                'content' => $content,
                'media_urls' => $media,
            ]);
        } catch (Throwable $e) {
            Credits::returnReply($request->user(), $paid, 'Inbox reply not sent');

            return Inbox::error($e, $account) ?? throw $e;
        }

        $row = ['direction' => 'out', 'content' => $content, 'media_urls' => $media, 'source' => 'api'] + $result;
        $row['status'] = $result['status'] ?? SocialMessage::PENDING;
        if ($row['status'] === SocialMessage::PENDING) {
            $row['sent_at'] = null;
        }

        [$message] = ($result['id'] ?? '') !== ''
            ? InboxStore::upsertMessage($conversation, $row, countUnread: false)
            : [null];

        $message ??= $conversation->messages()->create([
            'direction' => SocialMessage::OUT,
            'content' => $content,
            'media_urls' => $media ?: null,
            'status' => SocialMessage::PENDING,
            'source' => 'api',
        ]);

        // Replying is reading.
        $conversation->forceFill(['unread_count' => 0])->save();
        ProductEvents::replySent($request->user(), 'dm', $account->platform->value);

        return response()->json(['data' => new SocialMessageResource($message)], 202);
    }

    public function read(Request $request, SocialConversation $conversation, SocialProviders $providers): JsonResponse
    {
        Gate::authorize('view', $conversation);

        try {
            $providers->for($conversation->account)->markConversationRead($conversation->provider_conversation_id);
        } catch (Throwable $e) {
            return Inbox::error($e, $conversation->account) ?? throw $e;
        }

        $conversation->forceFill(['unread_count' => 0])->save();

        return response()->json(['data' => new SocialConversationResource($conversation->load('account'))]);
    }

    public function update(Request $request, SocialConversation $conversation): JsonResponse
    {
        Gate::authorize('reply', $conversation);
        $validated = $request->validate([
            'status' => ['required', Rule::in([SocialConversation::OPEN, SocialConversation::DONE])],
        ]);

        $conversation->update(['status' => $validated['status']]);

        return response()->json(['data' => new SocialConversationResource($conversation->load('account'))]);
    }

    /** Counts for the badges: in total and per account. */
    public function summary(Request $request): JsonResponse
    {
        $ids = Inbox::userIds($request->user());

        $threads = SocialConversation::query()->whereIn('user_id', $ids)
            ->selectRaw('social_account_id, sum(case when unread_count > 0 then 1 else 0 end) as unread_conversations, coalesce(sum(unread_count), 0) as unread_messages, sum(case when status = ? then 1 else 0 end) as open_conversations', [SocialConversation::OPEN])
            ->groupBy('social_account_id')->get()->keyBy('social_account_id');
        $comments = SocialComment::query()->whereIn('user_id', $ids)
            ->selectRaw('social_account_id, sum(case when read_at is null then 1 else 0 end) as unread_comments, sum(case when replied_at is null and private_replied_at is null then 1 else 0 end) as unreplied_comments')
            ->groupBy('social_account_id')->get()->keyBy('social_account_id');

        $accounts = SocialAccount::query()->whereIn('user_id', $ids)
            ->whereIn('id', $threads->keys()->merge($comments->keys())->unique())
            ->get(['id', 'ulid', 'platform', 'username'])
            ->map(fn (SocialAccount $a) => [
                'id' => $a->ulid,
                'platform' => $a->platform,
                'username' => $a->username,
                'unread_conversations' => (int) ($threads[$a->id]->unread_conversations ?? 0),
                'unread_messages' => (int) ($threads[$a->id]->unread_messages ?? 0),
                'unread_comments' => (int) ($comments[$a->id]->unread_comments ?? 0),
                'unreplied_comments' => (int) ($comments[$a->id]->unreplied_comments ?? 0),
            ])->values();

        $unreadConversations = $accounts->sum('unread_conversations');
        $unreadComments = $accounts->sum('unread_comments');

        return response()->json(['data' => [
            'unread_conversations' => $unreadConversations,
            'unread_messages' => $accounts->sum('unread_messages'),
            'open_conversations' => (int) $threads->sum('open_conversations'),
            'unread_comments' => $unreadComments,
            'unreplied_comments' => $accounts->sum('unreplied_comments'),
            // The one number for the nav badge.
            'total_unread' => $unreadConversations + $unreadComments,
            'accounts' => $accounts,
        ]]);
    }

    /**
     * Opening the Inbox backfills each account that never synced and
     * refreshes each one last synced more than REFRESH_AFTER_MINUTES ago
     * (queued; the hourly sync keeps it going between opens).
     */
    public static function firstSync(User $user): void
    {
        SocialAccount::query()
            ->whereIn('user_id', Inbox::userIds($user))
            ->where('provider', 'outstand')
            ->usable()
            ->whereIn('platform', [SocialPlatform::Instagram, SocialPlatform::Facebook])
            // Never synced, or not in the last few minutes: opening Engage
            // refreshes it (names, threads, comments) instead of waiting for
            // the hourly run. SyncInbox is unique per account for 15 min, so
            // repeated opens queue one job, not a storm.
            ->where(fn ($q) => $q->whereNull('inbox_synced_at')
                ->orWhere('inbox_synced_at', '<', now()->subMinutes(self::REFRESH_AFTER_MINUTES)))
            ->pluck('id')
            ->each(fn (int $id) => SyncInbox::dispatch($id));
    }

    /**
     * Library files as the URLs the provider fetches: images only, from
     * this workspace's library.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function mediaUrls(Request $request, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $files = File::query()
            ->whereIn('ulid', $ids)
            ->whereIn('user_id', Inbox::userIds($request->user()))
            ->where('status', File::STATUS_READY)
            ->get()
            ->keyBy('ulid');

        if ($files->count() !== count($ids)) {
            throw ValidationException::withMessages(['file_ids' => 'Choose files from your library.']);
        }

        if ($files->contains(fn (File $f) => ! str_starts_with((string) $f->mime_type, 'image/'))) {
            throw ValidationException::withMessages(['file_ids' => 'Instagram DMs take images only.']);
        }

        return array_map(fn (string $id) => $files[$id]->url(), $ids);
    }
}
