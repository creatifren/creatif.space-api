<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Support\Credits;
use App\Support\SocialPostRelay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The editor's side of approval and conversation: approve a held post
 * yourself, make an approval link for a client, comment, reply, resolve,
 * and apply a client's caption suggestion.
 */
class SocialApprovalController extends Controller
{
    /** The states an owner may approve out of — every held one. */
    private const HELD = [SocialPostApproval::Awaiting, SocialPostApproval::Client, SocialPostApproval::Revision];

    /** A link's ceiling: what one client can reasonably review at once. */
    private const MAX_POSTS = 50;

    /**
     * Approve a held post as its owner — it goes to the provider now.
     */
    public function approve(Request $request, SocialPost $socialPost, SocialPostRelay $relay): SocialPostResource|JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        if (! $socialPost->held() || $socialPost->status !== SocialPostStatus::Scheduled) {
            return response()->json(['message' => 'This post is not waiting for approval.'], 422);
        }

        if (! $relay->approve($socialPost, $request->user()->name, self::HELD)) {
            return response()->json(['message' => 'This post is not waiting for approval.'], 422);
        }

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

        return response()->json(['data' => $this->linkArray($this->withCounts($link))], 201);
    }

    /**
     * Every link the user made — active, expired and revoked — newest
     * first, each with where its posts stand. The Approval links page
     * (RF-043); the Feed's menu keeps the active ones itself.
     *
     * ponytail: capped, no paging. Page it once accounts hold ~200 links.
     */
    public function links(Request $request): JsonResponse
    {
        $links = $this->withCounts(SocialApprovalLink::query())
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $links->map(fn ($l) => $this->linkArray($l))->all()]);
    }

    /**
     * One link and its posts, with the threads still open on each. A
     * revoked link still shows: it is history, read-only.
     */
    public function show(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialApprovalLink->user_id === $user->id);

        return response()->json(['data' => $this->detail($socialApprovalLink)]);
    }

    /**
     * Rename it, change who it is for, set a new expiry (the URL stays the
     * same — that is the point of Extend), add posts, take posts off.
     * A revoked link is final: make a new one instead.
     */
    public function update(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialApprovalLink->user_id === $user->id);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expires_in_hours' => ['sometimes', 'nullable', 'integer', Rule::in([48, 168, 720])],
            'add_post_ids' => ['sometimes', 'array', 'max:'.self::MAX_POSTS],
            'add_post_ids.*' => ['string'],
            'remove_post_ids' => ['sometimes', 'array', 'max:'.self::MAX_POSTS],
            'remove_post_ids.*' => ['string'],
        ]);

        $link = $socialApprovalLink;
        if ($link->revoked_at !== null) {
            return response()->json(['message' => 'This link was revoked for good — make a new one from List.'], 422);
        }

        $add = isset($validated['add_post_ids'])
            ? $request->user()->socialPosts()
                ->whereIn('ulid', array_unique($validated['add_post_ids']))
                ->where('status', SocialPostStatus::Scheduled)
                ->whereNull('provider_post_id')
                ->whereNotIn('id', $link->posts()->select('social_posts.id'))
                ->pluck('id')
            : collect();
        $remove = isset($validated['remove_post_ids'])
            ? $link->posts()->whereIn('ulid', array_unique($validated['remove_post_ids']))->pluck('social_posts.id')
            : collect();

        $after = $link->posts()->count() + $add->count() - $remove->count();
        if ($after > self::MAX_POSTS) {
            return response()->json(['message' => 'A link holds at most '.self::MAX_POSTS.' posts.'], 422);
        }
        if ($after < 1) {
            return response()->json(['message' => 'A link needs at least one post — revoke it instead.'], 422);
        }

        DB::transaction(function () use ($link, $validated, $add, $remove): void {
            $fields = array_intersect_key($validated, array_flip(['title', 'client_name']));
            if (array_key_exists('expires_in_hours', $validated)) {
                $hours = $validated['expires_in_hours'];
                $fields['expires_at'] = $hours ? now()->addHours($hours) : null;
                $fields['extended_at'] = now();
            }
            if ($fields !== []) {
                $link->update($fields);
            }

            if ($add->isNotEmpty()) {
                $link->posts()->syncWithoutDetaching($add);
                // Only a post nobody has been asked about yet starts waiting
                // for the client; an answer already given stands.
                SocialPost::query()->whereIn('id', $add)
                    ->where('approval', SocialPostApproval::Awaiting->value)
                    ->update(['approval' => SocialPostApproval::Client->value]);
            }

            if ($remove->isNotEmpty()) {
                $link->posts()->detach($remove);
                $this->releaseHeld($remove, $link);
            }
        });

        return response()->json(['data' => $this->detail($link->fresh())]);
    }

    /**
     * Group approval from the editor's side (RF-043): every post on the
     * link still waiting for the client goes out now. A post the client
     * asked changes on is skipped — overriding an explicit "not yet" in
     * bulk is a mistake waiting to happen; it can still be approved alone.
     *
     * Per-post results, not all-or-nothing: a sent post cannot be unsent,
     * so the Credits for the batch are checked first and a provider
     * failure is reported on the post it happened to.
     */
    public function approveAll(Request $request, SocialApprovalLink $socialApprovalLink, SocialPostRelay $relay): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialApprovalLink->user_id === $user->id);
        $link = $socialApprovalLink;

        if ($link->revoked_at !== null) {
            return response()->json(['message' => 'This link was revoked — approve its posts one by one in the Feed.'], 422);
        }

        $waiting = $link->posts()
            ->where('status', SocialPostStatus::Scheduled)
            ->where('approval', SocialPostApproval::Client->value)
            ->orderBy('scheduled_at')
            ->get();

        if ($waiting->isEmpty()) {
            return response()->json(['message' => 'Nothing on this link is waiting.'], 422);
        }

        $need = SocialPostRelay::need($waiting);
        $balance = Credits::balance($request->user());
        if ($balance < $need) {
            return response()->json([
                'message' => "These need {$need} Credits and the balance has {$balance} — top up, then try again.",
            ], 422);
        }

        $results = [];
        foreach ($waiting as $post) {
            $message = null;
            try {
                $approved = $relay->approve($post, $request->user()->name, [SocialPostApproval::Client]);
            } catch (ValidationException $e) {
                [$approved, $message] = [false, $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                [$approved, $message] = [false, 'Post for Me did not accept it — try again.'];
            }
            $results[] = ['id' => $post->ulid, 'approved' => $approved, 'message' => $message];
        }

        return response()->json(['data' => [
            'link' => $this->linkArray($this->withCounts($link)),
            'results' => $results,
        ]]);
    }

    /**
     * The live link a waiting post is on — what Remind hands back to share
     * again. A client has no address with us (a name is all the link asks
     * for), so a reminder is the same link sent once more, by the editor.
     */
    public function linkFor(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $link = $this->withCounts(SocialApprovalLink::query())
            ->whereHas('posts', fn ($q) => $q->whereKey($socialPost->id))
            ->live()
            ->latest('id')
            ->first();

        return $link === null
            ? response()->json(['message' => 'This post is not on a live approval link — share it from List.'], 404)
            : response()->json(['data' => $this->linkArray($link)]);
    }

    /**
     * Revoke is final. Posts the client had not answered come back to the
     * owner (awaiting) unless another link still asks for them; approved
     * posts stay approved and a request for changes stays on the to-do.
     */
    public function revoke(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialApprovalLink->user_id === $user->id);

        if ($socialApprovalLink->revoked_at === null) {
            DB::transaction(function () use ($socialApprovalLink): void {
                $socialApprovalLink->update(['revoked_at' => now()]);
                $this->releaseHeld($socialApprovalLink->posts()->pluck('social_posts.id'), $socialApprovalLink);
            });
        }

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
     * Posts the client had not answered go back to the owner — unless a
     * link that is not revoked still holds them. Expired links count: one
     * can be extended, and a post reset under it would vanish from the
     * client's page when it reopens.
     *
     * @param  Collection<int, int>  $postIds
     */
    private function releaseHeld(Collection $postIds, SocialApprovalLink $except): void
    {
        SocialPost::query()
            ->whereIn('id', $postIds)
            ->where('approval', SocialPostApproval::Client->value)
            ->whereDoesntHave('approvalLinks', fn ($q) => $q
                ->whereKeyNot($except->id)
                ->whereNull('revoked_at'))
            ->update([
                'approval' => SocialPostApproval::Awaiting->value,
                'approval_by' => null,
                'approval_at' => null,
            ]);
    }

    /**
     * Where a link's posts stand, counted in the same query as the links.
     * Per post, not per link: approval lives on the post, so a post on two
     * links reads the same on both.
     *
     * @template T of Builder<SocialApprovalLink>|SocialApprovalLink
     *
     * @param  T  $target
     * @return T
     */
    private function withCounts(Builder|SocialApprovalLink $target): Builder|SocialApprovalLink
    {
        $counts = [
            'posts',
            'posts as approved_count' => fn ($q) => $q->where('approval', SocialPostApproval::Approved->value),
            'posts as waiting_count' => fn ($q) => $q->where('approval', SocialPostApproval::Client->value),
            'posts as revision_count' => fn ($q) => $q->where('approval', SocialPostApproval::Revision->value),
        ];

        return $target instanceof Builder ? $target->withCount($counts) : $target->loadCount($counts);
    }

    /**
     * @return array{link: array<string, mixed>, posts: array<int, mixed>}
     */
    private function detail(SocialApprovalLink $link): array
    {
        $posts = $link->posts()
            ->with(['targets.account', 'comments' => fn ($q) => $q->whereNull('resolved_at')->with('replies')])
            ->orderBy('scheduled_at')
            ->get();

        return [
            'link' => $this->linkArray($this->withCounts($link)),
            'posts' => SocialPostResource::collection($posts)->resolve(),
        ];
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
            'status' => $link->status(),
            'counts' => [
                'approved' => (int) ($link->approved_count ?? 0),
                'waiting' => (int) ($link->waiting_count ?? 0),
                'revision' => (int) ($link->revision_count ?? 0),
            ],
            'expires_at' => $link->expires_at?->toIso8601String(),
            'extended_at' => $link->extended_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
