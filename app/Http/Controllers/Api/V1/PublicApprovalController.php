<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Notifications\SocialPostDecided;
use App\Support\Credits;
use App\Support\SocialPostRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * creatif.space/a/{token} — the client's side. No account, by design: the
 * token is the credential and a typed name is the identity. Every action
 * is scoped to the posts ON this link; a post id from anywhere else 404s.
 *
 * Ladder, same as /r/{slug}: 404 unknown, 410 expired, 409 revoked.
 */
class PublicApprovalController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $link = $this->resolve($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        $posts = $link->posts()
            ->with(['targets.account', 'comments.replies'])
            ->orderBy('scheduled_at')
            ->get();

        return response()->json(['data' => [
            'editor' => $link->user->name,
            'title' => $link->title,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'posts' => $posts->map(fn (SocialPost $p) => $this->postArray($p))->all(),
        ]]);
    }

    /**
     * Approve, or ask for changes (with the reason as a comment). An
     * approval sends the post to the provider; a reason is required to
     * ask for changes — an empty one leaves the editor nothing to act on.
     */
    public function decide(Request $request, string $token, string $post, SocialPostRelay $relay): JsonResponse
    {
        $link = $this->resolve($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'decision' => ['required', 'in:approve,revise'],
            'reason' => ['required_if:decision,revise', 'nullable', 'string', 'max:5000'],
        ]);

        $socialPost = $link->posts()->where('ulid', $post)->firstOrFail();

        if ($socialPost->status !== SocialPostStatus::Scheduled
            || ! in_array($socialPost->approval, [SocialPostApproval::Client, SocialPostApproval::Revision], true)) {
            return response()->json(['message' => 'This post has already been decided.'], 422);
        }

        if ($validated['decision'] === 'approve') {
            $outcome = $this->approveFor($link, $socialPost, $validated['name'], $relay);
            if ($outcome === false) {
                return response()->json(['message' => 'This post has already been decided.'], 422);
            }
            if (is_string($outcome)) {
                return response()->json(['message' => $outcome], 422);
            }
            $link->user->notify(new SocialPostDecided($link, SocialPostDecided::APPROVED, $validated['name'], 1, $socialPost));
        } else {
            $socialPost->update([
                'approval' => SocialPostApproval::Revision,
                'approval_by' => $validated['name'],
                'approval_at' => now(),
            ]);
            SocialPostComment::create([
                'social_post_id' => $socialPost->id,
                'author_name' => $validated['name'],
                'role' => 'APPROVER',
                'body' => $validated['reason'],
            ]);
            $link->user->notify(new SocialPostDecided(
                $link, SocialPostDecided::REVISE, $validated['name'], 1, $socialPost,
                str($validated['reason'])->limit(200)->toString(),
            ));
        }

        return response()->json(['data' => $this->postArray($socialPost->fresh(['targets.account', 'comments.replies']))]);
    }

    /**
     * Approve all — the client's group approval (RF-043). One request for
     * the posts the client was looking at: `post_ids` is what their page
     * showed, so a post the editor added a minute ago is not approved
     * unseen.
     *
     * Not all-or-nothing, and it cannot be: each approval hands a post to
     * the provider, which no transaction can take back. What can be decided
     * up front is: the Credits for the whole batch are checked before any
     * post leaves, and a provider failure on one post is reported on that
     * post while the rest go through.
     *
     * ponytail: synchronous — a 50-post batch waits on 50 provider calls.
     * Queue it when links routinely carry more than ~20 posts.
     */
    public function approveAll(Request $request, string $token, SocialPostRelay $relay): JsonResponse
    {
        $link = $this->resolve($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'post_ids' => ['required', 'array', 'min:1', 'max:50'],
            'post_ids.*' => ['string'],
        ]);

        $eligible = $link->posts()
            ->whereIn('ulid', array_unique($validated['post_ids']))
            ->where('status', SocialPostStatus::Scheduled)
            ->whereIn('approval', [SocialPostApproval::Client->value, SocialPostApproval::Revision->value])
            ->orderBy('scheduled_at')
            ->get();

        if ($eligible->isEmpty()) {
            return response()->json(['message' => 'These posts have already been decided.'], 422);
        }

        $need = SocialPostRelay::need($eligible);
        $balance = Credits::balance($link->user);
        if ($balance < $need) {
            $link->user->notify(new SocialPostDecided(
                $link, SocialPostDecided::BLOCKED, $validated['name'], $eligible->count(), null,
                "Approving these needs {$need} Credits and the balance has {$balance} — top up, then send them from Approval links.",
            ));

            return response()->json(['message' => $this->blockedMessage($link)], 422);
        }

        $results = [];
        $approved = 0;
        foreach ($eligible as $post) {
            $outcome = $this->approveFor($link, $post, $validated['name'], $relay);
            $results[] = [
                'id' => $post->ulid,
                'approved' => $outcome === true,
                'message' => is_string($outcome) ? $outcome : null,
            ];
            $approved += $outcome === true ? 1 : 0;
        }

        if ($approved > 0) {
            $link->user->notify(new SocialPostDecided(
                $link, SocialPostDecided::APPROVED, $validated['name'], $approved,
                $approved === 1 ? $eligible->first(fn ($p) => $p->approval === SocialPostApproval::Approved) : null,
            ));
        }

        $posts = $link->posts()->with(['targets.account', 'comments.replies'])->orderBy('scheduled_at')->get();

        return response()->json(['data' => [
            'posts' => $posts->map(fn (SocialPost $p) => $this->postArray($p))->all(),
            'results' => $results,
        ]]);
    }

    /**
     * A client's yes, through the one approve path. True when it went,
     * false when someone decided first, or the sentence the client may
     * read when it could not go — the real reason (a short balance, a
     * provider refusal) goes to the owner, never to the client: their page
     * is not where the editor's Credits are discussed.
     */
    private function approveFor(SocialApprovalLink $link, SocialPost $post, string $name, SocialPostRelay $relay): bool|string
    {
        try {
            return $relay->approve($post, $name, [SocialPostApproval::Client, SocialPostApproval::Revision]);
        } catch (ValidationException $e) {
            $why = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $why = 'Post for Me did not accept it — try sending it again from Approval links.';
        }

        $link->user->notify(new SocialPostDecided($link, SocialPostDecided::BLOCKED, $name, 1, $post, $why));

        return $this->blockedMessage($link);
    }

    private function blockedMessage(SocialApprovalLink $link): string
    {
        return "This can’t go out right now — {$link->user->name} has been told.";
    }

    /**
     * Take a decision back — Undo while fresh, Take it back later; the
     * server does not tell the two apart. An approved post is pulled from
     * the provider (its Credits come back) and waits again. Only while it
     * has not gone out: history cannot be undecided. The retraction is
     * written as a comment, never by erasing the old line.
     */
    public function retract(Request $request, string $token, string $post, SocialPostRelay $relay): JsonResponse
    {
        $link = $this->resolve($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $socialPost = $link->posts()->where('ulid', $post)->firstOrFail();
        $was = $socialPost->approval;

        if ($socialPost->status !== SocialPostStatus::Scheduled
            || ! in_array($was, [SocialPostApproval::Approved, SocialPostApproval::Revision], true)) {
            return response()->json(['message' => 'This post has already gone out — it cannot be taken back.'], 422);
        }

        if ($was === SocialPostApproval::Approved) {
            $relay->recall($socialPost, 'Approval taken back');
        }

        $socialPost->update(['approval' => SocialPostApproval::Client, 'approval_by' => null, 'approval_at' => null]);
        SocialPostComment::create([
            'social_post_id' => $socialPost->id,
            'author_name' => $validated['name'],
            'role' => 'APPROVER',
            'body' => $was === SocialPostApproval::Approved ? 'Approval taken back.' : 'Request for changes taken back.',
        ]);

        return response()->json(['data' => $this->postArray($socialPost->fresh(['targets.account', 'comments.replies']))]);
    }

    /**
     * A comment, a reply, or a caption suggestion from the client.
     */
    public function comment(Request $request, string $token, string $post): JsonResponse
    {
        $link = $this->resolve($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['sometimes', 'nullable', 'string'],
            'suggestion' => ['sometimes', 'nullable', 'string', 'max:63206'],
        ]);

        $socialPost = $link->posts()->where('ulid', $post)->firstOrFail();
        $parent = isset($validated['parent_id'])
            ? $socialPost->comments()->where('ulid', $validated['parent_id'])->firstOrFail()
            : null;

        $comment = SocialPostComment::create([
            'social_post_id' => $socialPost->id,
            'parent_id' => $parent?->id,
            'author_name' => $validated['name'],
            'role' => 'COMMENTER',
            'body' => $validated['body'],
            'suggestion' => $parent ? null : ($validated['suggestion'] ?? null),
        ]);

        return response()->json(['data' => $comment->toPublicArray()], 201);
    }

    private function resolve(string $token): SocialApprovalLink|JsonResponse
    {
        $link = SocialApprovalLink::query()->where('token', $token)->with('user')->first();

        if ($link === null) {
            abort(404);
        }
        if ($link->revoked_at !== null) {
            // The name, as on 410: the dead page tells the client whom to ask.
            return response()->json(['message' => 'This link was taken back.', 'editor' => $link->user->name], 409);
        }
        if ($link->expired()) {
            return response()->json(['message' => 'This link has expired.', 'editor' => $link->user->name], 410);
        }

        return $link;
    }

    /**
     * What a client may see of a post: no ids beyond its own, no provider
     * fields, no workspace label.
     *
     * @return array<string, mixed>
     */
    private function postArray(SocialPost $post): array
    {
        return [
            'id' => $post->ulid,
            'caption' => $post->caption,
            'media' => $post->media ?? [],
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'approval' => $post->approval?->value,
            'approval_by' => $post->approval_by,
            'approval_at' => $post->approval_at?->toIso8601String(),
            'targets' => $post->targets->map(fn ($t) => [
                'platform' => $t->account->platform,
                'username' => $t->account->username,
                'caption' => $t->configuration['caption'] ?? null,
            ])->all(),
            'comments' => $post->comments
                ->filter(fn (SocialPostComment $c) => $c->resolved_at === null)
                ->map(fn (SocialPostComment $c) => $c->toPublicArray())->values()->all(),
        ];
    }
}
