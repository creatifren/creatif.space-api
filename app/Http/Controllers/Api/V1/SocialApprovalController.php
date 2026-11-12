<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Exceptions\AccountReconnectRequired;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PublishPreflightFailed;
use App\Exceptions\ScheduleBeyondHorizon;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\SocialAccount;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use App\Models\SocialPostComment;
use App\Models\User;
use App\Notifications\ApprovalLinkShared;
use App\Notifications\ApprovalReminder;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\PlanScope;
use App\Support\ProductEvents;
use App\Support\ScheduleHorizon;
use App\Support\SocialPostRelay;
use App\Support\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
        self::writable($request->user(), $socialPost->user_id);

        if (! $socialPost->held() || $socialPost->status !== SocialPostStatus::Scheduled) {
            return response()->json(['message' => 'This post is not waiting for approval.'], 422);
        }

        if (! $relay->approve($socialPost, $request->user()->name, self::HELD)) {
            return response()->json(['message' => 'This post is not waiting for approval.'], 422);
        }

        return new SocialPostResource($socialPost->load('targets.account'));
    }

    /**
     * One link for a set of the user's held posts — or a Content Plan: a
     * date range (in `timezone`, default the app's) and optional channels,
     * showing every workspace post inside it, including later ones. Posts
     * on a fixed list switch to waiting for the client. Returns the URL to
     * share by hand.
     */
    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // A fixed list of posts, or a scope — one of the two.
            'post_ids' => ['required_without:date_from', 'prohibits:date_from,date_to', 'array', 'min:1', 'max:50'],
            'post_ids.*' => ['string'],
            ...self::scopeRules(),
            // The share dialog's choices; absent = no expiry.
            'expires_in_hours' => ['sometimes', 'nullable', 'integer', Rule::in([48, 168, 720])],
            // Shown to the client above the posts.
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            // The editor's own note on who it is for; the client never sees it.
            'client_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Who may decide: the client signs in with this address (Google
            // or a one-time email link) to approve or ask for changes.
            'approver_email' => ['required', 'string', 'email:rfc', 'max:254'],
            // Email the link to the approver now; absent/false = share it by hand.
            'notify_approver' => ['sometimes', 'boolean'],
        ], [
            'approver_email.required' => 'Add the email address of the person who approves. They sign in with it to decide.',
            'approver_email.email' => 'That is not an email address.',
        ]);

        PlanQuota::requireClientApprovals($request->user(), isset($validated['date_from']) ? 'date_from' : 'post_ids');

        if (isset($validated['date_from'])) {
            return $this->createPlan($request, $validated);
        }

        // Held posts, and drafts (API clients share a draft straight to a
        // client — the same hold that sending it with approval=client makes).
        abort_unless(Workspace::canWrite($request->user()), 403, 'Viewers cannot share posts for approval.');

        $posts = SocialPost::query()->whereIn('user_id', self::workspaceIds($request->user()))
            ->whereIn('ulid', array_unique($validated['post_ids']))
            ->where(fn ($q) => $q
                ->where(fn ($held) => $held->where('status', SocialPostStatus::Scheduled)->whereNull('provider_post_id'))
                ->orWhere('status', SocialPostStatus::Draft))
            ->get();

        if ($posts->isEmpty()) {
            return response()->json(['message' => 'None of these posts can still be approved.'], 422);
        }

        // Plans v2 limits (Free): one client at a time, each post's slot in
        // the scheduling window, and drafts joining the queue fit in it.
        $user = $request->user();
        PlanQuota::requireApprovalClient($user, $validated['client_name'] ?? null, null, 'approver_email', $validated['approver_email']);
        self::requireWindow($user, $posts, 'post_ids');
        self::requireHorizon($posts, 'post_ids');
        PlanQuota::requireQueueRoom($user, $posts->where('status', SocialPostStatus::Draft)->count(), 'post_ids');

        $hours = $validated['expires_in_hours'] ?? null;
        $link = SocialApprovalLink::create([
            // The workspace's link, whoever on the team made it.
            'user_id' => Workspace::owner($request->user())->id,
            'title' => $validated['title'] ?? null,
            'client_name' => $validated['client_name'] ?? null,
            'approver_email' => $validated['approver_email'],
            'expires_at' => $hours ? now()->addHours($hours) : null,
        ]);
        $link->posts()->attach($posts->pluck('id'));
        SocialPost::query()->whereIn('id', $posts->pluck('id'))
            ->update(['approval' => SocialPostApproval::Client->value, 'status' => SocialPostStatus::Scheduled->value]);
        ProductEvents::approvalLinkCreated($link, $posts->count(), $hours);

        return $this->created($link, $validated);
    }

    /**
     * A Content Plan link: no posts attached; the scope decides. Free's
     * one-client rule applies as to any link; its 7-day window applies to
     * deciding (PlanScope::decidability), not to what the plan shows.
     *
     * @param  array<string, mixed>  $validated
     */
    private function createPlan(Request $request, array $validated): JsonResponse
    {
        $user = $request->user();
        abort_unless(Workspace::canWrite($user), 403, 'Viewers cannot share posts for approval.');
        PlanQuota::requireApprovalClient($user, $validated['client_name'] ?? null, null, 'approver_email', $validated['approver_email']);

        $hours = $validated['expires_in_hours'] ?? null;
        $link = SocialApprovalLink::create([
            'user_id' => Workspace::owner($user)->id,
            'title' => $validated['title'] ?? null,
            'client_name' => $validated['client_name'] ?? null,
            'approver_email' => $validated['approver_email'],
            'expires_at' => $hours ? now()->addHours($hours) : null,
        ] + self::scopeFields($user, $validated));

        ProductEvents::approvalLinkCreated($link, PlanScope::posts($link)->count(), $hours);

        return $this->created($link, $validated);
    }

    /**
     * The 201 for a new link; emails it to the approver first when asked
     * (notify_approver), and says whether it did.
     *
     * @param  array<string, mixed>  $validated
     */
    private function created(SocialApprovalLink $link, array $validated): JsonResponse
    {
        $notify = (bool) ($validated['notify_approver'] ?? false);
        if ($notify) {
            Notification::route('mail', $link->approver_email)->notify(new ApprovalLinkShared($link));
        }

        return response()->json(['data' => $this->linkArray($this->withCounts($link)) + ['approver_notified' => $notify]], 201);
    }

    /**
     * Validation for a Content Plan's scope.
     *
     * @return array<string, list<mixed>>
     */
    private static function scopeRules(): array
    {
        return [
            // Calendar dates in `timezone`; both ends included.
            'date_from' => ['required_without:post_ids', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            // Social account ids (ulids) of the workspace; absent/null = all.
            'channel_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'channel_ids.*' => ['string', 'max:26', 'distinct'],
        ];
    }

    /**
     * The stored scope from validated input; checks the span and that every
     * channel is the workspace's.
     *
     * @param  array<string, mixed>  $validated
     * @param  SocialApprovalLink|null  $link  the plan being changed (its values fill what is not sent)
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function scopeFields(User $user, array $validated, ?SocialApprovalLink $link = null): array
    {
        $from = $validated['date_from'] ?? $link?->scope_from?->format('Y-m-d');
        $to = $validated['date_to'] ?? $link?->scope_to?->format('Y-m-d');
        $tz = array_key_exists('timezone', $validated)
            ? ($validated['timezone'] ?: (string) config('app.timezone'))
            : (($link === null ? null : $link->scope_timezone) ?? (string) config('app.timezone'));

        if ($from === null || $to === null || $to < $from) {
            throw ValidationException::withMessages(['date_to' => 'The plan ends before it starts.']);
        }
        [$starts, $ends] = PlanScope::bounds($from, $to, $tz);
        if ($starts->diffInDays($ends) > PlanScope::MAX_DAYS) {
            throw ValidationException::withMessages(['date_to' => 'A Content Plan covers at most '.PlanScope::MAX_DAYS.' days.']);
        }

        $accountIds = $link?->scope_account_ids;
        if (array_key_exists('channel_ids', $validated)) {
            $ulids = array_values(array_unique((array) ($validated['channel_ids'] ?? [])));
            $accounts = $ulids === [] ? collect()
                : SocialAccount::query()->where('user_id', Workspace::owner($user)->id)->whereIn('ulid', $ulids)->pluck('id');
            if ($accounts->count() !== count($ulids)) {
                throw ValidationException::withMessages(['channel_ids' => 'One or more channels are not connected accounts of this workspace.']);
            }
            $accountIds = $ulids === [] ? null : $accounts->map(fn ($id) => (int) $id)->sort()->values()->all();
        }

        return [
            'scope_from' => $from,
            'scope_to' => $to,
            'scope_timezone' => $tz,
            'scope_starts_at' => $starts,
            'scope_ends_at' => $ends,
            'scope_account_ids' => $accountIds,
        ];
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
            ->whereIn('user_id', self::workspaceIds($request->user()))
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
        self::readable($request->user(), $socialApprovalLink->user_id);

        return response()->json(['data' => $this->detail($socialApprovalLink)]);
    }

    /**
     * Rename it, change who it is for, set a new expiry (the URL stays the
     * same — that is the point of Extend), add posts, take posts off.
     * A revoked link is final: make a new one instead.
     */
    public function update(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        self::writable($request->user(), $socialApprovalLink->user_id);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Change who may decide (not removable: a link always has one
            // once set).
            'approver_email' => ['sometimes', 'string', 'email:rfc', 'max:254'],
            'expires_in_hours' => ['sometimes', 'nullable', 'integer', Rule::in([48, 168, 720])],
            'add_post_ids' => ['sometimes', 'array', 'max:'.self::MAX_POSTS],
            'add_post_ids.*' => ['string'],
            'remove_post_ids' => ['sometimes', 'array', 'max:'.self::MAX_POSTS],
            'remove_post_ids.*' => ['string'],
            // Content Plan only: move or narrow the scope.
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'channel_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'channel_ids.*' => ['string', 'max:26', 'distinct'],
            // Content Plan reminder email to the approver, on or off.
            'reminders' => ['sometimes', 'boolean'],
        ]);

        $link = $socialApprovalLink;
        if ($link->revoked_at !== null) {
            return response()->json(['message' => 'This link was revoked for good — make a new one from List.'], 422);
        }

        $scopeKeys = array_intersect_key($validated, array_flip(['date_from', 'date_to', 'timezone', 'channel_ids']));
        if ($link->isScoped() && (isset($validated['add_post_ids']) || isset($validated['remove_post_ids']))) {
            return response()->json(['message' => 'A Content Plan shows every post in its dates — change the dates or channels instead.', 'errors' => ['add_post_ids' => ['A Content Plan has no post list.']]], 422);
        }
        if (! $link->isScoped() && $scopeKeys !== []) {
            return response()->json(['message' => 'This link is a fixed list of posts — make a Content Plan to share a date range.', 'errors' => ['date_from' => ['Not a Content Plan.']]], 422);
        }
        $scope = $scopeKeys === [] ? [] : self::scopeFields($request->user(), $validated, $link);

        // Asking the client about more posts is a new request; renaming,
        // extending or taking posts off a link already sent is not.
        if (! empty($validated['add_post_ids'])) {
            PlanQuota::requireClientApprovals($request->user(), 'add_post_ids');
        }
        // Naming a different client is asking someone new (plans v2): by
        // approver_email when the link has one, by client_name on old links.
        $newName = array_key_exists('client_name', $validated) ? $validated['client_name'] : $link->client_name;
        $newEmail = $validated['approver_email'] ?? $link->approver_email;
        if (PlanQuota::approvalIdentity($newName, $link->id, $newEmail) !== PlanQuota::approvalIdentity($link->client_name, $link->id, $link->approver_email)) {
            PlanQuota::requireApprovalClient($request->user(), $newName, $link,
                isset($validated['approver_email']) ? 'approver_email' : 'client_name', $newEmail);
        }

        $add = isset($validated['add_post_ids'])
            ? SocialPost::query()->whereIn('user_id', self::workspaceIds($request->user()))
                ->whereIn('ulid', array_unique($validated['add_post_ids']))
                ->where('status', SocialPostStatus::Scheduled)
                ->whereNull('provider_post_id')
                ->whereNotIn('id', $link->posts()->select('social_posts.id'))
                ->pluck('id')
            : collect();
        if ($add->isNotEmpty()) {
            $adding = SocialPost::query()->whereIn('id', $add)->get();
            self::requireWindow($request->user(), $adding, 'add_post_ids');
            self::requireHorizon($adding, 'add_post_ids');
        }
        $remove = isset($validated['remove_post_ids'])
            ? $link->posts()->whereIn('ulid', array_unique($validated['remove_post_ids']))->pluck('social_posts.id')
            : collect();

        // A fixed list's size; a Content Plan has no list.
        $after = $link->isScoped() ? 1 : $link->posts()->count() + $add->count() - $remove->count();
        if ($after > self::MAX_POSTS) {
            return response()->json(['message' => 'A link holds at most '.self::MAX_POSTS.' posts.'], 422);
        }
        if ($after < 1) {
            return response()->json(['message' => 'A link needs at least one post — revoke it instead.'], 422);
        }

        DB::transaction(function () use ($link, $validated, $add, $remove, $scope): void {
            $fields = array_intersect_key($validated, array_flip(['title', 'client_name', 'approver_email']));
            if (array_key_exists('reminders', $validated)) {
                $fields['reminders_enabled'] = (bool) $validated['reminders'];
            }
            if (isset($fields['approver_email'])) {
                // Re-addressed by the owner: no longer a first-use binding.
                $fields['approver_bound_at'] = null;
            }
            if (array_key_exists('expires_in_hours', $validated)) {
                $hours = $validated['expires_in_hours'];
                $fields['expires_at'] = $hours ? now()->addHours($hours) : null;
                $fields['extended_at'] = now();
            }
            $fields += $scope;
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
        self::writable($request->user(), $socialApprovalLink->user_id);
        $link = $socialApprovalLink;

        if ($link->revoked_at !== null) {
            return response()->json(['message' => 'This link was revoked — approve its posts one by one in the Feed.'], 422);
        }

        $waiting = PlanScope::posts($link)
            ->where('status', SocialPostStatus::Scheduled)
            ->where('approval', SocialPostApproval::Client->value)
            ->orderBy('scheduled_at')
            ->get();

        if ($waiting->isEmpty()) {
            return response()->json(['message' => 'Nothing on this link is waiting.'], 422);
        }

        $need = SocialPostRelay::need($request->user(), $waiting);
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
            } catch (ValidationException|AccountReconnectRequired|PublishPreflightFailed|NotEnoughCredits|ScheduleBeyondHorizon $e) {
                [$approved, $message] = [false, $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                [$approved, $message] = [false, PublicApprovalController::refusedBy($post, $e).' Try again.'];
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
        self::readable($request->user(), $socialPost->user_id);

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
     * Every post put to a client has its slot inside the plan's scheduling
     * window (a post with no slot goes out on approval — always inside).
     *
     * @param  Collection<int, SocialPost>  $posts
     */
    private static function requireWindow(User $user, Collection $posts, string $field): void
    {
        foreach ($posts as $post) {
            PlanQuota::requireScheduleWindow($user, $post->scheduled_at, $field);
        }
    }

    /**
     * A post put to a client must be one the relay can take on its slot:
     * the owner hears it now (422 with the latest date), not the client
     * after their yes.
     *
     * @param  Collection<int, SocialPost>  $posts
     */
    private static function requireHorizon(Collection $posts, string $field): void
    {
        foreach ($posts as $post) {
            ScheduleHorizon::require($post->provider, $post->scheduled_at, $field);
        }
    }

    /**
     * Revoke is final. Posts the client had not answered come back to the
     * owner (awaiting) unless another link still asks for them; approved
     * posts stay approved and a request for changes stays on the to-do.
     */
    /** One reminder per link per this many hours. */
    public const REMIND_EVERY_HOURS = 12;

    /**
     * POST /social-approval-links/{id}/remind — "Send a reminder": email the
     * approver once more about the posts still waiting on this link, at most
     * once every 12 hours. 422 when there is no one to remind or nothing to
     * remind about; 429 reminder_too_soon (with next_reminder_at) inside the
     * 12 hours. The claim is one conditional UPDATE, so two clicks at once
     * send one email.
     */
    public function remind(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        self::writable($request->user(), $socialApprovalLink->user_id);
        $link = $socialApprovalLink;

        if ($link->revoked_at !== null || $link->expired()) {
            return response()->json(['message' => 'This link is no longer live — make a new one, or extend it first.', 'code' => 'link_not_live'], 422);
        }
        if ($link->approver_email === null) {
            return response()->json(['message' => 'This link has no approver email to remind.', 'code' => 'no_approver'], 422);
        }
        // The approver turned reminders off for this link (the Content Plan
        // reminder's one-click opt-out, #102): that covers these too.
        if (! $link->remindersOn()) {
            return response()->json(['message' => 'Your client turned reminders off for this link.', 'code' => 'reminders_off'], 422);
        }
        $waiting = (int) $this->linkArray($this->withCounts($link))['by_state'][PlanScope::STATE_WAITING];
        if ($waiting < 1) {
            return response()->json(['message' => 'Nothing on this link is waiting for an answer.', 'code' => 'nothing_waiting'], 422);
        }

        $now = now();
        $claimed = SocialApprovalLink::query()
            ->whereKey($link->getKey())
            ->where(fn ($q) => $q->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<=', $now->copy()->subHours(self::REMIND_EVERY_HOURS)))
            ->update(['last_reminded_at' => $now]);
        if ($claimed === 0) {
            $next = $link->fresh()?->last_reminded_at?->copy()->addHours(self::REMIND_EVERY_HOURS);

            return response()->json([
                'message' => 'A reminder went out less than '.self::REMIND_EVERY_HOURS.' hours ago.',
                'code' => 'reminder_too_soon',
                'next_reminder_at' => $next?->toIso8601String(),
            ], 429, $next ? ['Retry-After' => (string) max(1, (int) ceil($now->diffInSeconds($next, true)))] : []);
        }

        Notification::route('mail', $link->approver_email)->notify(new ApprovalReminder($link->fresh() ?? $link, $waiting));

        return response()->json(['data' => [
            'link_id' => $link->ulid,
            'reminded_at' => $now->toIso8601String(),
            'next_reminder_at' => $now->copy()->addHours(self::REMIND_EVERY_HOURS)->toIso8601String(),
            'waiting' => $waiting,
        ]]);
    }

    public function revoke(Request $request, SocialApprovalLink $socialApprovalLink): JsonResponse
    {
        self::writable($request->user(), $socialApprovalLink->user_id);

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
        self::readable($request->user(), $socialPost->user_id);

        return response()->json([
            'data' => $socialPost->comments()->with('replies')->get()
                ->map(fn (SocialPostComment $c) => $c->toPublicArray())->all(),
        ]);
    }

    public function comment(Request $request, SocialPost $socialPost): JsonResponse
    {
        self::writable($request->user(), $socialPost->user_id);

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
        self::writable($request->user(), $socialPostComment->post->user_id);
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
        self::writable($request->user(), $post->user_id);

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
            // The rest of the states, for by_state — still one query.
            'posts as held_count' => fn ($q) => $q->where('status', SocialPostStatus::Scheduled->value)
                ->where('approval', SocialPostApproval::Awaiting->value),
            'posts as scheduled_count' => fn ($q) => $q->where('status', SocialPostStatus::Scheduled->value)
                ->whereNull('approval'),
            'posts as draft_count' => fn ($q) => $q->where('status', SocialPostStatus::Draft->value),
            'posts as published_count' => fn ($q) => $q->where('status', SocialPostStatus::Published->value),
            'posts as approved_open_count' => fn ($q) => $q->where('status', SocialPostStatus::Scheduled->value)
                ->where('approval', SocialPostApproval::Approved->value),
        ];

        return $target instanceof Builder ? $target->withCount($counts) : $target->loadCount($counts);
    }

    /**
     * @return array{link: array<string, mixed>, posts: array<int, mixed>}
     */
    private function detail(SocialApprovalLink $link): array
    {
        $posts = PlanScope::posts($link)
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
        $plan = $link->isScoped() ? PlanScope::counts($link) : null;

        return [
            'id' => $link->ulid,
            'url' => $link->url(),
            'title' => $link->title,
            'client_name' => $link->client_name,
            // Null on links made before signed-in approvers until someone binds it.
            'approver_email' => $link->approver_email,
            'approver_bound_at' => $link->approver_bound_at?->toIso8601String(),
            // 'list' (fixed posts) or 'plan' (Content Plan: scope below).
            'kind' => $link->isScoped() ? 'plan' : 'list',
            'scope' => PlanScope::toArray($link),
            'posts' => $plan === null ? ($link->posts_count ?? 0) : $plan['total'],
            'status' => $link->status(),
            // approved / waiting / revision as before; by_state adds every
            // state (waiting, changes_requested, approved, scheduled, draft).
            'counts' => $plan === null ? [
                'approved' => (int) ($link->approved_count ?? 0),
                'waiting' => (int) ($link->waiting_count ?? 0),
                'revision' => (int) ($link->revision_count ?? 0),
            ] : [
                'approved' => $plan[PlanScope::STATE_APPROVED],
                'waiting' => $plan[PlanScope::STATE_WAITING],
                'revision' => $plan[PlanScope::STATE_CHANGES],
            ],
            'by_state' => $plan ?? [
                'total' => (int) ($link->posts_count ?? 0),
                PlanScope::STATE_WAITING => (int) ($link->waiting_count ?? 0) + (int) ($link->held_count ?? 0),
                PlanScope::STATE_CHANGES => (int) ($link->revision_count ?? 0),
                PlanScope::STATE_APPROVED => (int) ($link->approved_open_count ?? 0),
                PlanScope::STATE_SCHEDULED => (int) ($link->scheduled_count ?? 0),
                PlanScope::STATE_DRAFT => (int) ($link->draft_count ?? 0),
                PlanScope::STATE_PUBLISHED => (int) ($link->published_count ?? 0),
            ],
            // Content Plans: one email to the approver 24 h before the first
            // undecided post (PATCH reminders: false turns it off).
            'reminders' => $link->remindersOn(),
            'reminded_at' => isset($link->getAttributes()['reminded_at']) ? $link->reminded_at?->toIso8601String() : null,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'extended_at' => $link->extended_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
            // Send a reminder: when the last one went, and when the next may.
            'last_reminded_at' => $link->last_reminded_at?->toIso8601String(),
            'next_reminder_at' => $link->last_reminded_at?->copy()->addHours(self::REMIND_EVERY_HOURS)->toIso8601String(),
        ];
    }

    /**
     * GET /me/approvals/waiting — "Waiting your approval": live links sent
     * to my verified email that still have posts waiting on my decision.
     * Newest first. Empty for an unverified address.
     */
    public function waiting(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at === null) {
            return response()->json(['data' => []]);
        }

        $links = SocialApprovalLink::query()
            ->live()
            ->where('approver_email', mb_strtolower(trim($user->email)))
            ->with('user:id,name')
            ->latest('id')
            ->limit(100)
            ->get();

        $rows = [];
        foreach ($links as $link) {
            // Fixed lists and Content Plans alike: what is waiting on me.
            $posts = PlanScope::posts($link)
                ->where('status', SocialPostStatus::Scheduled)
                ->whereIn('approval', array_map(fn (SocialPostApproval $a) => $a->value, PlanScope::decidableFrom($link)))
                ->whereNot('approval', SocialPostApproval::Revision->value)
                ->orderBy('scheduled_at')
                ->get();
            if ($posts->isEmpty()) {
                continue;
            }

            $rows[] = [
                'link' => [
                    // For MCP decide_approval (`link`); the URL works too.
                    'id' => $link->ulid,
                    'url' => $link->url(),
                    'title' => $link->title,
                    'editor' => $link->user->name,
                    'kind' => $link->isScoped() ? 'plan' : 'list',
                    'scope' => PlanScope::toArray($link),
                    'expires_at' => $link->expires_at?->toIso8601String(),
                    'created_at' => $link->created_at?->toIso8601String(),
                ],
                'waiting' => $posts->count(),
                'posts' => $posts->map(fn (SocialPost $p) => [
                    'id' => $p->ulid,
                    'caption' => str($p->caption)->limit(140)->toString(),
                    'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                    'approval' => $p->approval?->value,
                    'decidable' => PlanScope::decidability($link, $p)['decidable'],
                ])->values()->all(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /me/reports/shared — "Reports shared with you": what other people
     * shared with this user to look at, newest first. Each row is
     * {report_id, kind, title, period: {from, to}, owner: {name}, url, …}.
     *
     * kind "content_plan": a live Content Plan (a scoped approval link)
     * whose approver_email is this user's verified email — the same rule
     * as "Waiting your approval". kind "insight" is reserved: Insight share
     * links (insight_share_links) are token-only and addressed to nobody, so
     * there is no one to list them for until they gain a recipient.
     *
     * Reached across workspaces only through the approver email, never by
     * id, so ScopeToWorkspace has nothing to check here.
     */
    public function sharedReports(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at === null) {
            return response()->json(['data' => []]);
        }

        $plans = SocialApprovalLink::query()
            ->live()
            ->whereNotNull('scope_from')
            ->where('approver_email', mb_strtolower(trim($user->email)))
            ->with('user:id,name')
            ->latest('id')
            ->limit(100)
            ->get();

        $rows = $plans->map(function (SocialApprovalLink $link) {
            $counts = PlanScope::counts($link);

            return [
                'report_id' => $link->ulid,
                'kind' => 'content_plan',
                'title' => $link->title,
                'period' => [
                    'from' => $link->scope_from?->format('Y-m-d'),
                    'to' => $link->scope_to?->format('Y-m-d'),
                    'timezone' => PlanScope::timezone($link),
                ],
                'owner' => ['name' => $link->user->name],
                'url' => $link->url(),
                'posts' => $counts['total'],
                'waiting' => $counts[PlanScope::STATE_WAITING],
                'expires_at' => $link->expires_at?->toIso8601String(),
                'created_at' => $link->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /social-posts/{post}/review {for_review: bool} — show a draft to
     * the client on a Content Plan covering its date (or stop showing it).
     * Drafts only: a scheduled post is on the plan anyway.
     */
    public function markForReview(Request $request, SocialPost $socialPost): JsonResponse
    {
        self::writable($request->user(), $socialPost->user_id);
        $validated = $request->validate(['for_review' => ['required', 'boolean']]);

        if ($socialPost->status !== SocialPostStatus::Draft) {
            return response()->json(['message' => 'Only a draft is marked for review — a scheduled post is on the plan already.'], 422);
        }

        $socialPost->update(['review_requested_at' => $validated['for_review'] ? now() : null]);

        return response()->json(['data' => [
            'id' => $socialPost->ulid,
            'for_review' => $socialPost->review_requested_at !== null,
            'review_requested_at' => $socialPost->review_requested_at?->toIso8601String(),
        ]]);
    }

    /**
     * Everyone whose posts and links are this workspace's: the owner and
     * their active team members (Workspace::owner), as in the rest of Social.
     *
     * @return Collection<int, int>
     */
    private static function workspaceIds(User $user): Collection
    {
        return Workspace::memberIds(Workspace::owner($user));
    }

    /**
     * Outside the caller's workspace a post or link does not exist (404 —
     * not 403, which would confirm the id); inside it, a viewer may read.
     */
    private static function readable(User $user, int $ownerId): void
    {
        abort_unless(self::mayRead($user, $ownerId), 404);
    }

    /** …and only a non-viewer may act (403 inside the workspace). */
    private static function writable(User $user, int $ownerId): void
    {
        self::readable($user, $ownerId);
        abort_unless(Workspace::canWrite($user), 403, 'Viewers cannot change posts or approval links.');
    }

    /** A team member sees the workspace's posts and links. */
    private static function mayRead(User $user, int $ownerId): bool
    {
        return Workspace::contains($user, $ownerId);
    }
}
