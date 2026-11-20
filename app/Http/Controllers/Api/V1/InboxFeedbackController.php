<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Support\ClientBadges;
use App\Support\Inbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Inbox › Feedback (prototype v10.1): what clients wrote on approval links —
 * every top-level APPROVER note on the workspace's posts, newest first.
 *
 * - open: not marked done (resolved_at is null);
 * - unread: open and not answered yet (no EDITOR reply under it);
 * - done: marked done (POST /social-comments/{id}/resolve).
 *
 * Answering is POST /social-posts/{post}/comments with parent_id (the
 * client sees it on the link); Mark done is the resolve route. No new
 * table: the note, its replies and resolved_at are the approval thread.
 *
 *   GET /api/v1/inbox/feedback?status=open|unread|done&client={label ulid}
 */
class InboxFeedbackController extends Controller
{
    private const LIMIT = 100;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'unread', 'done'])],
            'client' => ['sometimes', 'string', 'max:26'],
        ]);
        $ids = Inbox::userIds($request->user());
        $status = $validated['status'] ?? 'open';

        $base = fn (): Builder => SocialPostComment::query()
            ->whereNull('parent_id')
            ->where('role', 'APPROVER')
            ->whereHas('post', fn (Builder $p) => $p->whereIn('user_id', $ids))
            ->when(isset($validated['client']), fn (Builder $q) => $q->whereHas('post.targets.account.clientLabels', fn (Builder $l) => $l->where('client_labels.ulid', $validated['client'])));
        $unread = fn (Builder $q): Builder => $q->whereNull('resolved_at')
            ->whereDoesntHave('replies', fn (Builder $r) => $r->where('role', 'EDITOR'));

        $query = match ($status) {
            'done' => $base()->whereNotNull('resolved_at'),
            'unread' => $unread($base()),
            default => $base()->whereNull('resolved_at'),
        };

        $rows = $query->with(['replies', 'post.targets'])->latest('id')->limit(self::LIMIT)->get();
        ClientBadges::prime($rows->map(fn (SocialPostComment $c) => $c->post)->filter());

        return response()->json([
            'data' => $rows->map(fn (SocialPostComment $c) => self::item($c))->values()->all(),
            'meta' => [
                'open' => $base()->whereNull('resolved_at')->count(),
                'unread' => $unread($base())->count(),
                'done' => $base()->whereNotNull('resolved_at')->count(),
            ],
        ]);
    }

    /**
     * Unanswered, open client notes — the Feedback tab's badge (InboxController::summary).
     *
     * @param  list<int>  $userIds
     */
    public static function unreadCount(array $userIds): int
    {
        return SocialPostComment::query()
            ->whereNull('parent_id')
            ->where('role', 'APPROVER')
            ->whereNull('resolved_at')
            ->whereHas('post', fn (Builder $p) => $p->whereIn('user_id', $userIds))
            ->whereDoesntHave('replies', fn (Builder $r) => $r->where('role', 'EDITOR'))
            ->count();
    }

    /** @return array<string, mixed> */
    private static function item(SocialPostComment $c): array
    {
        $post = $c->post;
        $media = $post instanceof SocialPost ? ($post->media[0]['url'] ?? null) : null;

        return $c->toPublicArray() + [
            'replied' => $c->replies->contains(fn (SocialPostComment $r) => $r->role === 'EDITOR'),
            'sample' => $post instanceof SocialPost && $post->is_demo,
            'clients' => $post instanceof SocialPost ? ClientBadges::forPost($post) : [],
            'post' => $post instanceof SocialPost ? [
                'id' => $post->ulid,
                'caption' => Str::limit((string) $post->caption, 140),
                'thumb_url' => is_string($media) ? $media : null,
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'status' => $post->status,
                'approval' => $post->approval,
            ] : null,
        ];
    }
}
