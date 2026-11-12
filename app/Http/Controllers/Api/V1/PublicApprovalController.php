<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\UserStatus;
use App\Exceptions\AccountReconnectRequired;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PublishPreflightFailed;
use App\Exceptions\ScheduleBeyondHorizon;
use App\Http\Controllers\Controller;
use App\Models\SocialApprovalDecision;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\User;
use App\Notifications\SocialPostDecided;
use App\Support\ApproverGate;
use App\Support\ApproverSignIn;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\PlanScope;
use App\Support\ProductEvents;
use App\Support\PublishFailure;
use App\Support\ScheduleHorizon;
use App\Support\SocialPostRelay;
use App\Support\Workspace;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * creatif.space/a/{token} — the client's side. Looking needs only the token.
 * Deciding (approve, ask for changes, take back) needs the invited approver
 * signed in — ApproverGate: 401 sign_in_required, 403 approver_mismatch —
 * and every decision is written to social_approval_decisions. The typed
 * name is kept for display only. Comments stay open to the token holder.
 * Every action is scoped to the posts ON this link; a post id from anywhere
 * else 404s.
 *
 * Ladder, same as /r/{slug}: 404 unknown, 410 expired, 409 revoked.
 */
class PublicApprovalController extends Controller
{
    /** What the approver reads when a post has no time yet (needs_time). */
    public const NEEDS_TIME = 'This post has no publish time yet, so it cannot be approved: approving would publish it at once. The sender has to set a time first.';

    /** The link this request resolved (postArray reads its rules). */
    private ?SocialApprovalLink $link = null;

    /** @var array<int, list<array<string, mixed>>> media per post id, primed for a page */
    private array $media = [];

    public function show(Request $request, string $token): JsonResponse
    {
        $link = $this->resolve($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        // Filters inside the link (Content Plan views): a channel, and a date
        // range in the link's timezone. Never wider than the link itself.
        $filters = $request->validate([
            'channel' => ['sometimes', 'nullable', 'string', 'max:26'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $posts = PlanScope::filtered($link, $filters['channel'] ?? null, $filters['from'] ?? null, $filters['to'] ?? null)
            ->with(['targets.account', 'comments.replies'])
            ->orderBy('scheduled_at')
            ->limit(500)
            ->get()
            // In time order: a post sent "now" sits where it was made.
            ->sortBy(fn (SocialPost $p) => PlanScope::when($p)?->getTimestamp() ?? 0)
            ->values();
        $this->media = PlanScope::media($posts);

        ProductEvents::approvalLinkOpened($link, $posts->count(), $request);

        return response()->json(['data' => [
            'editor' => $link->user->name,
            'title' => $link->title,
            'expires_at' => $link->expires_at?->toIso8601String(),
            // The owner's plan, as public Spaces read it: the page hides its
            // footer slogan when branding is removed.
            'branding_removed' => Workspace::owner($link->user)->plan()->feature('branding_removed'),
            // Who may decide, and whether the person looking can.
            'approver' => ApproverGate::viewer($request, $link),
            // Content Plan: the scope (null on a fixed-list link), the
            // channels to filter by, the filters applied, and per-state counts.
            'kind' => $link->isScoped() ? 'plan' : 'list',
            'scope' => PlanScope::toArray($link),
            'channels' => PlanScope::channels($link),
            'filters' => [
                'channel' => $filters['channel'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'counts' => PlanScope::counts($link),
            'window_days' => PlanQuota::scheduleWindowDays($link->user),
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

        $approver = ApproverGate::authorize($request, $link);

        $validated = $request->validate([
            // Display only now; the signed-in approver is the identity.
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'decision' => ['required', 'in:approve,revise'],
            'reason' => ['required_if:decision,revise', 'nullable', 'string', 'max:5000'],
        ]);

        return $this->decideAs($request, $link, $post, $approver, $validated, $relay, 'web');
    }

    /**
     * THE decision path — /a (signed-in session) and MCP decide_approval
     * both end here, so the rules are one: in scope, still decidable, the
     * Free window, bind-on-first-use, idempotent repeats, one audit row.
     * The caller has already checked who the approver is.
     *
     * @param  array{decision: string, reason?: string|null, name?: string|null}  $validated
     * @param  'web'|'mcp'  $source
     */
    public function decideAs(Request $request, SocialApprovalLink $link, string $post, User $approver, array $validated, SocialPostRelay $relay, string $source): JsonResponse
    {
        $this->link = $link;
        $name = self::displayName($validated['name'] ?? null, $approver);

        $socialPost = PlanScope::posts($link)->where('ulid', $post)->firstOrFail();

        // The same decision again (a retry, a beacon after the fetch, a
        // double tap) is answered with where the post stands, not refused.
        if ($this->sameDecision($socialPost, $validated)) {
            return $this->current($socialPost);
        }

        if ($socialPost->status !== SocialPostStatus::Scheduled
            || ! in_array($socialPost->approval, PlanScope::decidableFrom($link), true)) {
            return response()->json(['message' => 'This post has already been decided.'], 422);
        }

        // Free: only posts inside the scheduling window can be decided yet.
        $window = PlanScope::decidability($link, $socialPost);
        if (! $window['decidable']) {
            return response()->json([
                'message' => 'This post can be decided closer to its date.',
                'reason' => 'outside_window',
                'decidable_from' => $window['decidable_from'],
            ], 422);
        }

        // No time on the post: a yes would publish it at once (the relay
        // sends a post without a slot now). Refused; the sender sets a time.
        if ($validated['decision'] === 'approve' && $socialPost->scheduled_at === null) {
            return response()->json([
                'message' => self::NEEDS_TIME,
                'reason' => 'needs_time',
            ], 422);
        }

        ApproverGate::bindIfUnbound($request, $link, $approver,
            $validated['decision'] === 'approve' ? SocialApprovalDecision::APPROVED : SocialApprovalDecision::CHANGES_REQUESTED);

        if ($validated['decision'] === 'approve') {
            $outcome = $this->approveFor($link, $socialPost, $name, $relay);
            if ($outcome === false) {
                // Someone else's request won the race: the same yes is fine.
                return $this->sameDecision($socialPost->refresh(), $validated)
                    ? $this->current($socialPost)
                    : response()->json(['message' => 'This post has already been decided.'], 422);
            }
            if (is_string($outcome)) {
                return response()->json(['message' => $outcome], 422);
            }
            ApproverGate::record($request, $link, $socialPost, SocialApprovalDecision::APPROVED, $approver, $name, $source);
            // A post held for its slot already told the owner (HELD).
            if (! SocialPostRelay::heldForLater($socialPost->refresh())) {
                $link->user->notify(new SocialPostDecided($link, SocialPostDecided::APPROVED, $name, 1, $socialPost));
            }
            ProductEvents::approvalDecision($link, $socialPost, 'approved');
        } else {
            $socialPost->update([
                'approval' => SocialPostApproval::Revision,
                'approval_by' => $name,
                'approval_at' => now(),
            ]);
            SocialPostComment::create([
                'social_post_id' => $socialPost->id,
                'author_name' => $name,
                'role' => 'APPROVER',
                'body' => $validated['reason'],
            ]);
            ApproverGate::record($request, $link, $socialPost, SocialApprovalDecision::CHANGES_REQUESTED, $approver, $name, $source, $validated['reason']);
            $link->user->notify(new SocialPostDecided(
                $link, SocialPostDecided::REVISE, $name, 1, $socialPost,
                str($validated['reason'])->limit(200)->toString(),
            ));
            ProductEvents::approvalDecision($link, $socialPost, 'changes_requested');
        }

        return $this->current($socialPost);
    }

    /**
     * Is this the decision the post already carries? Approve: it was
     * approved (and may have gone out since). Changes: it is waiting for
     * changes and the client's latest word on it is this same reason.
     *
     * @param  array<string, mixed>  $validated
     */
    private function sameDecision(SocialPost $post, array $validated): bool
    {
        if ($validated['decision'] === 'approve') {
            return $post->approval === SocialPostApproval::Approved;
        }

        if ($post->approval !== SocialPostApproval::Revision) {
            return false;
        }

        $last = $post->comments()->whereNull('parent_id')->where('role', 'APPROVER')->latest('id')->first();

        return $last !== null && trim((string) $last->body) === trim((string) ($validated['reason'] ?? ''));
    }

    private function current(SocialPost $post): JsonResponse
    {
        return response()->json(['data' => $this->postArray($post->fresh(['targets.account', 'comments.replies']))]);
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

        $approver = ApproverGate::authorize($request, $link);

        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'post_ids' => ['required', 'array', 'min:1', 'max:50'],
            'post_ids.*' => ['string'],
        ]);
        $name = self::displayName($validated['name'] ?? null, $approver);

        $eligible = PlanScope::posts($link)
            ->whereIn('ulid', array_unique($validated['post_ids']))
            ->where('status', SocialPostStatus::Scheduled)
            ->whereIn('approval', array_map(fn (SocialPostApproval $a) => $a->value, PlanScope::decidableFrom($link)))
            ->orderBy('scheduled_at')
            ->get()
            // Free: only what is inside the scheduling window yet.
            ->filter(fn (SocialPost $p) => PlanScope::decidability($link, $p)['decidable'])
            ->values();

        if ($eligible->isEmpty()) {
            // Approve-all again, once everything it named is approved: the
            // same answer as the first time, nothing sent twice.
            $named = PlanScope::posts($link)->whereIn('ulid', array_unique($validated['post_ids']))->get();
            if ($named->isNotEmpty() && $named->every(fn (SocialPost $p) => $p->approval === SocialPostApproval::Approved)) {
                $posts = PlanScope::posts($link)->with(['targets.account', 'comments.replies'])->orderBy('scheduled_at')->get();

                return response()->json(['data' => [
                    'posts' => $posts->map(fn (SocialPost $p) => $this->postArray($p))->all(),
                    'results' => $named->map(fn (SocialPost $p) => ['id' => $p->ulid, 'approved' => true, 'message' => null])->values()->all(),
                ]]);
            }

            return response()->json(['message' => 'These posts have already been decided.'], 422);
        }

        $need = SocialPostRelay::need($link->user, $eligible);
        $balance = Credits::balance($link->user);
        if ($balance < $need) {
            $link->user->notify(new SocialPostDecided(
                $link, SocialPostDecided::BLOCKED, $name, $eligible->count(), null,
                "Approving these needs {$need} Credits and the balance has {$balance} — top up, then send them from Approval links.",
            ));

            return response()->json(['message' => $this->blockedMessage($link)], 422);
        }

        ApproverGate::bindIfUnbound($request, $link, $approver, SocialApprovalDecision::APPROVED);

        $results = [];
        $approved = 0;
        foreach ($eligible as $post) {
            $outcome = $this->approveFor($link, $post, $name, $relay);
            $results[] = [
                'id' => $post->ulid,
                'approved' => $outcome === true,
                'message' => is_string($outcome) ? $outcome : null,
            ];
            $approved += $outcome === true ? 1 : 0;
            if ($outcome === true) {
                ApproverGate::record($request, $link, $post, SocialApprovalDecision::APPROVED, $approver, $name);
                ProductEvents::approvalDecision($link, $post, 'approved');
            }
        }

        if ($approved > 0) {
            $link->user->notify(new SocialPostDecided(
                $link, SocialPostDecided::APPROVED, $name, $approved,
                $approved === 1 ? $eligible->first(fn ($p) => $p->approval === SocialPostApproval::Approved) : null,
            ));
        }

        $posts = PlanScope::posts($link)->with(['targets.account', 'comments.replies'])->orderBy('scheduled_at')->get();

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
        // Approve-all included: a post with no time is never sent by a yes.
        if ($post->scheduled_at === null) {
            return self::NEEDS_TIME;
        }

        try {
            $approved = $relay->approve($post, $name, PlanScope::decidableFrom($link), holdBeyondHorizon: true);

            // A slot further out than the relay takes: the yes stands and
            // the post goes out by itself once it is close enough. The
            // owner is told; the client's tap is never a dead end.
            if ($approved && SocialPostRelay::heldForLater($post->refresh())) {
                $link->user->notify(new SocialPostDecided($link, SocialPostDecided::HELD, $name, 1, $post, self::heldReason($post)));
            }

            return $approved;
        } catch (ValidationException|AccountReconnectRequired|PublishPreflightFailed|NotEnoughCredits|ScheduleBeyondHorizon $e) {
            $why = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $why = self::refusedBy($post, $e).' Try sending it again from Approval links.';
        }

        $link->user->notify(new SocialPostDecided($link, SocialPostDecided::BLOCKED, $name, 1, $post, $why));

        return $this->blockedMessage($link);
    }

    /** What the owner reads about a post approved but waiting for its slot. */
    public static function heldReason(SocialPost $post): string
    {
        $days = ScheduleHorizon::days($post->provider);
        $from = $days === null ? null : $post->scheduled_at?->copy()->subDays($days);

        return 'It is scheduled for '.$post->scheduled_at?->format('j M Y, H:i').' UTC, further ahead than the networks accept'
            .($days !== null ? " (at most {$days} days)" : '')
            .'. It stays approved and is sent automatically'
            .($from !== null ? ' from '.$from->format('j M Y') : ' once it is close enough')
            .'. Nothing else to do.';
    }

    /** "{Network} did not accept it: {reason}" — never the relay's name. */
    public static function refusedBy(SocialPost $post, Throwable $e): string
    {
        $networks = $post->targets()->with('account')->get()
            ->map(fn ($t) => $t->account?->platform?->value)->filter()->unique()
            ->map(fn ($n) => PublishFailure::networkName($n))->values();
        $network = $networks->isEmpty() ? 'The network' : $networks->join(', ', ' and ');

        $raw = $e instanceof RequestException
            ? ($e->response->json('message') ?? $e->response->json('error.message') ?? $e->response->json('error') ?? $e->response->body())
            : $e->getMessage();
        $reason = PublishFailure::detail(is_string($raw) ? $raw : json_encode($raw)) ?? 'no reason given';

        return "{$network} did not accept it: {$reason}.";
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

        $approver = ApproverGate::authorize($request, $link);

        $validated = $request->validate(['name' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $name = self::displayName($validated['name'] ?? null, $approver);
        $socialPost = PlanScope::posts($link)->where('ulid', $post)->firstOrFail();
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
            'author_name' => $name,
            'role' => 'APPROVER',
            'body' => $was === SocialPostApproval::Approved ? 'Approval taken back.' : 'Request for changes taken back.',
        ]);
        ApproverGate::record($request, $link, $socialPost, SocialApprovalDecision::RETRACTED, $approver, $name);
        ProductEvents::approvalDecision($link, $socialPost, 'retracted');

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

        $socialPost = PlanScope::posts($link)->where('ulid', $post)->firstOrFail();
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

    /**
     * Sign in by email: a one-time link (15 minutes, single use) that signs
     * in — or creates — the account for that address and returns to the
     * approval page. Sent only when the address is the link's approver
     * (any address on an old, still unbound link). Always 202 with the same
     * words, so the endpoint never says whether an address matched.
     */
    public function emailLink(Request $request, string $token): JsonResponse
    {
        $link = $this->resolve($token);
        if ($link instanceof JsonResponse) {
            return $link;
        }

        $validated = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = mb_strtolower(trim($validated['email']));

        ApproverSignIn::send($link, $email);

        return response()->json([
            'message' => 'If that address can approve this link, a sign-in link is on its way. It works once, for 15 minutes.',
        ], 202);
    }

    /** What the approver typed, else their account name. */
    private static function displayName(?string $typed, User $approver): string
    {
        $typed = trim((string) $typed);

        return $typed !== '' ? $typed : ($approver->name ?: $approver->email);
    }

    private function resolve(string $token): SocialApprovalLink|JsonResponse
    {
        $link = SocialApprovalLink::query()->where('token_hash', SocialApprovalLink::hashToken($token))->with('user')->first();

        // An owner whose account is being deleted is gone to the public.
        if ($link === null || $link->user === null || $link->user->status === UserStatus::PendingDeletion) {
            abort(404);
        }
        if ($link->revoked_at !== null) {
            // The name, as on 410: the dead page tells the client whom to ask.
            return response()->json(['message' => 'This link was taken back.', 'editor' => $link->user->name], 409);
        }
        if ($link->expired()) {
            return response()->json(['message' => 'This link has expired.', 'editor' => $link->user->name], 410);
        }

        $this->link = $link;

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
        $link = $this->link;
        $window = $link === null ? ['decidable' => false, 'decidable_from' => null] : PlanScope::decidability($link, $post);
        $tz = $link === null ? (string) config('app.timezone') : PlanScope::timezone($link);

        return [
            'id' => $post->ulid,
            'caption' => $post->caption,
            'media' => $post->media ?? [],
            // View-agnostic (Grid / Calendar / Feed): every channel the post
            // goes to, media with kind and aspect, one state, and whether the
            // client may decide on it now.
            'channels' => $post->targets->map(fn ($t) => [
                'id' => $t->account->ulid,
                'platform' => $t->account->platform->value,
                'handle' => $t->account->username,
            ])->values()->all(),
            'media_items' => $this->media[$post->id] ?? (PlanScope::media(collect([$post]))[$post->id] ?? []),
            'state' => PlanScope::state($post),
            'decidable' => $window['decidable'],
            'decidable_from' => $window['decidable_from'],
            'local_date' => PlanScope::when($post)?->setTimezone($tz)->format('Y-m-d'),
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
