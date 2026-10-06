<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Support\SocialPostRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The editor's side of approval and conversation: approve a held post
 * yourself, make an approval link for a client, comment, reply, resolve,
 * and apply a client's caption suggestion.
 */
class SocialApprovalController extends Controller
{
    /**
     * Approve a held post as its owner — it goes to the provider now.
     */
    public function approve(Request $request, SocialPost $socialPost, SocialPostRelay $relay): SocialPostResource|JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        if (! $socialPost->held() || $socialPost->status !== SocialPostStatus::Scheduled) {
            return response()->json(['message' => 'This post is not waiting for approval.'], 422);
        }

        // Provider first: a failed send leaves the post held, not half-approved.
        $relay->send($socialPost);
        $socialPost->update([
            'approval' => SocialPostApproval::Approved,
            'approval_by' => $request->user()->name,
            'approval_at' => now(),
        ]);

        return new SocialPostResource($socialPost->load('targets.account'));
    }

    /**
     * One link for a set of the user's held posts. Posts on it switch to
     * waiting for the client. Returns the URL to share by hand.
     */
    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_ids' => ['required', 'array', 'min:1', 'max:50'],
            'post_ids.*' => ['string'],
            // The share dialog's choices; absent = no expiry.
            'expires_in_hours' => ['sometimes', 'nullable', 'integer', Rule::in([48, 168, 720])],
            // Shown to the client above the posts.
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            // The editor's own note on who it is for; the client never sees it.
            'client_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $posts = $request->user()->socialPosts()
            ->whereIn('ulid', array_unique($validated['post_ids']))
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNull('provider_post_id')
            ->get();

        if ($posts->isEmpty()) {
            return response()->json(['message' => 'None of these posts can still be approved.'], 422);
        }

        $hours = $validated['expires_in_hours'] ?? null;
        $link = SocialApprovalLink::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
            'client_name' => $validated['client_name'] ?? null,
            'expires_at' => $hours ? now()->addHours($hours) : null,
        ]);
        $link->posts()->attach($posts->pluck('id'));
        SocialPost::query()->whereIn('id', $posts->pluck('id'))
            ->update(['approval' => SocialPostApproval::Client->value]);

        return response()->json(['data' => $this->linkArray($link->loadCount('posts'))], 201);
    }

    /**
     * The user's live links, newest first — the Feed's Approval links menu.
     */
    public function links(Request $request): JsonResponse
    {
        $links = SocialApprovalLink::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->withCount('posts')
            ->latest()
            ->get();

        return response()->json(['data' => $links->map(fn ($l) => $this->linkArray($l))->all()]);
    }

    /**
     * The live link a waiting post is on — what Remind hands back to share
     * again. A client has no address with us (a name is all the link asks
     * for), so a reminder is the same link sent once more, by the editor.
     */
    public function linkFor(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $link = SocialApprovalLink::query()
            ->whereHas('posts', fn ($q) => $q->whereKey($socialPost->id))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->withCount('posts')
            ->latest('id')
            ->first();

        return $link === null
            ? response()->json(['message' => 'This post is not on a live approval link — share it from List.'], 404)
            : response()->json(['data' => $this->linkArray($link)]);
    }

    public function revoke(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialApprovalLink->user_id === $user->id);
        $socialApprovalLink->update(['revoked_at' => now()]);

        return response()->json(null, 204);
    }

    /**
     * Threads on a post, oldest first, replies inline.
     */
    public function comments(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        return response()->json([
            'data' => $socialPost->comments()->with('replies')->get()
                ->map(fn (SocialPostComment $c) => $c->toPublicArray())->all(),
        ]);
    }

    public function comment(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $parent = isset($validated['parent_id'])
            ? $socialPost->comments()->where('ulid', $validated['parent_id'])->firstOrFail()
            : null;

        $comment = SocialPostComment::create([
            'social_post_id' => $socialPost->id,
            'parent_id' => $parent?->id,
            'user_id' => $request->user()->id,
            'author_name' => $request->user()->name,
            'role' => 'EDITOR',
            'body' => $validated['body'],
        ]);

        return response()->json(['data' => $comment->toPublicArray()], 201);
    }

    /**
     * Resolve hides a thread; it is never deleted.
     */
    public function resolve(Request $request, SocialPostComment $socialPostComment): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPostComment->post->user_id === $user->id);
        $socialPostComment->update(['resolved_at' => now()]);

        return response()->json(['data' => $socialPostComment->toPublicArray()]);
    }

    /**
     * Apply a client's caption suggestion: the caption changes and the
     * thread is resolved. Held posts only — a sent one would need the
     * provider replaced, which Edit does.
     */
    public function applySuggestion(Request $request, SocialPostComment $socialPostComment): SocialPostResource|JsonResponse
    {
        $post = $socialPostComment->post;
        Gate::allowIf(fn ($user) => $post->user_id === $user->id);

        if ($socialPostComment->suggestion === null || ! $post->held()) {
            return response()->json(['message' => 'There is no suggestion to apply here.'], 422);
        }

        $post->update(['caption' => $socialPostComment->suggestion]);
        $socialPostComment->update(['resolved_at' => now()]);

        return new SocialPostResource($post->load('targets.account'));
    }

    /**
     * @return array<string, mixed>
     */
    private function linkArray(SocialApprovalLink $link): array
    {
        return [
            'id' => $link->ulid,
            'url' => $link->url(),
            'title' => $link->title,
            'client_name' => $link->client_name,
            'posts' => $link->posts_count ?? 0,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
