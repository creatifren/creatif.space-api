<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Exceptions\AccountReconnectRequired;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CapTokenSends;
use App\Http\Resources\SocialPostResource;
use App\Models\Activity;
use App\Models\File;
use App\Models\SocialPost;
use App\Models\User;
use App\Contracts\SocialProvider;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\ProductEvents;
use App\Support\SendConfirmation;
use App\Support\SocialPostRelay;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialPostController extends Controller
{
    /**
     * The user's posts — Scheduled and Published tabs are one endpoint with
     * a status filter.
     */
    public function index(Request $request, SocialPostRelay $relay): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(SocialPostStatus::class)],
            // Narrowing for API clients (the MCP server): a window on the
            // post's slot — or, with no slot, when it was made — one
            // channel, and a cap. All optional; the app sends none of them.
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'account_id' => ['sometimes', 'string', 'max:26'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // A post whose slot passed still reading Scheduled means a result
        // webhook went missing; ask before answering.
        $relay->reconcile($request->user());

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : null;

        return SocialPostResource::collection(
            $request->user()->socialPosts()
                ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($from, fn ($q, $from) => $q->where(fn ($w) => $w
                    ->where('scheduled_at', '>=', $from)
                    ->orWhere(fn ($u) => $u->whereNull('scheduled_at')->where('created_at', '>=', $from))))
                ->when($to, fn ($q, $to) => $q->where(fn ($w) => $w
                    ->where('scheduled_at', '<=', $to)
                    ->orWhere(fn ($u) => $u->whereNull('scheduled_at')->where('created_at', '<=', $to))))
                ->when($validated['account_id'] ?? null, fn ($q, $account) => $q->whereHas(
                    'targets.account',
                    fn ($a) => $a->where('ulid', $account),
                ))
                ->when($validated['limit'] ?? null, fn ($q, $limit) => $q->limit((int) $limit))
                // Threads ride along: the Feed shows every post's conversation
                // at once, so one request beats one per card.
                ->with(['targets.account', 'comments.replies', 'user:id,name'])
                ->latest()
                ->get(),
        )->additional(['meta' => [
            // The composer's "uses N of your Credits" line: Monthly Credits
            // go first, then Top-up (`credits`). posts_* are the legacy
            // allowance, kept for older screens.
            'credits' => Credits::balance($request->user()),
            'per_send' => Credits::perSend(),
            'per_send_by_platform' => Credits::perSendByPlatform(),
            'monthly_balance' => Credits::monthlyBalance($request->user()),
            'monthly_resets_at' => Credits::cycleEnds(Workspace::owner($request->user()))->toIso8601String(),
            'posts_used' => Credits::allowanceUsed($request->user()),
            'posts_limit' => PlanQuota::socialPostsLimit($request->user()),
            'posts_reset_at' => Credits::allowanceSince(Workspace::owner($request->user()))
                ->addMonthNoOverflow()->toIso8601String(),
        ]]);
    }

    /**
     * One post, with its destinations and its review thread — what the
     * list returns per row, for a caller that holds only the id.
     */
    public function show(Request $request, SocialPost $socialPost): SocialPostResource
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        return new SocialPostResource($socialPost->load(['targets.account', 'comments.replies', 'user:id,name']));
    }

    /**
     * Create (and optionally schedule) a post. The ownership check on
     * account_ids is the tenant boundary: only the user's own Connected
     * accounts resolve to provider ids, and nothing is sent otherwise.
     */
    public function store(Request $request, SocialProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            // Facebook's cap — the largest of the nine; per-platform limits
            // are the composer's concern.
            'caption' => ['required', 'string', 'max:63206'],
            'account_ids' => ['required', 'array', 'min:1', 'max:20'],
            'account_ids.*' => ['string'],
            // Per-account caption override: { <account ulid>: "text" }.
            'captions' => ['sometimes', 'array'],
            'captions.*' => ['string', 'max:63206'],
            'media' => ['sometimes', 'array', 'max:10'],
            'media.*.url' => ['required', 'url:https'],
            // A video's cover, handed to the platforms that take one.
            'media.*.thumbnail_url' => ['sometimes', 'nullable', 'url:https'],
            // Library files by id (API clients): resolved here to the same
            // signed URLs the composer sends, after any `media`.
            'file_ids' => ['sometimes', 'array', 'max:10'],
            'file_ids.*' => ['string', 'max:26', 'distinct'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            // Off when absent. `awaiting` waits for the editor's own yes,
            // `client` for a client on an approval link; either holds the
            // post here — nothing reaches the provider until approved.
            'approval' => ['sometimes', 'nullable', Rule::in(['awaiting', 'client'])],
            // A draft is only saved: no provider, no quota. Sending it later
            // goes through the same gate as a new post.
            'draft' => ['sometimes', 'boolean'],
            // Reels and Story are placements on Facebook, Instagram and
            // Threads; other platforms post it as an ordinary post.
            'type' => ['sometimes', Rule::in(['post', 'reels', 'story'])],
            'labels' => ['sometimes', 'nullable', 'array', 'max:10'],
            'labels.*' => ['string', 'max:40'],
        ]);

        $user = $request->user();

        if (isset($validated['file_ids']) && $validated['file_ids'] !== []) {
            $validated['media'] = [...($validated['media'] ?? []), ...$this->libraryMedia($user, $validated['file_ids'])];

            if (count($validated['media']) > 10) {
                throw ValidationException::withMessages(['file_ids' => 'A post takes up to 10 media items.']);
            }
        }

        $approval = isset($validated['approval']) ? SocialPostApproval::from($validated['approval']) : null;
        if ($approval === SocialPostApproval::Client) {
            PlanQuota::requireClientApprovals($user);
        }
        $draft = (bool) ($validated['draft'] ?? false);
        $ids = array_values(array_unique($validated['account_ids']));
        $sendsNow = $approval === null && ! $draft;

        // Only usable accounts: Connected, under the current relay. One the
        // user owns but left under another relay (or disconnected) gets a
        // named "reconnect" answer; anything else is not theirs.
        $accounts = $user->socialAccounts()->whereIn('ulid', $ids)->get();
        AccountReconnectRequired::unlessUsable($accounts);

        if ($accounts->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'account_ids' => 'One or more accounts are not connected accounts of yours.',
            ]);
        }

        $captions = $validated['captions'] ?? [];

        // Monthly Credits first, then Top-up, each destination at its price
        // (X more with a link), checked before anything reaches the
        // provider. Held posts and drafts are checked when they are approved
        // or sent — holding one costs nothing.
        $destinations = $accounts->map(fn ($a) => [$a->platform, $captions[$a->ulid] ?? $validated['caption']])->all();
        if ($sendsNow && ! PlanQuota::canPostSocial($user, $destinations)) {
            $need = Credits::sendPrice($user, $destinations);

            throw ValidationException::withMessages([
                'caption' => "This needs {$need} Credits past this month's and the balance has ".Credits::balance($user)
                    .' — top up, or send to fewer accounts.',
            ]);
        }
        $scheduledAt = isset($validated['scheduled_at'])
            ? Carbon::parse($validated['scheduled_at'])
            : null;

        // The post's public ulid doubles as the provider external_id, so a
        // webhook can find the row even before provider_post_id lands.
        $ulid = (string) str()->ulid();

        $type = $validated['type'] ?? 'post';
        $configurations = $accounts
            ->map(fn ($a) => SocialPostRelay::configuration(
                $a->provider_account_id,
                $a->platform->value,
                isset($captions[$a->ulid]) ? ['caption' => $captions[$a->ulid]] : null,
                $type,
            ))
            ->filter()
            ->values();

        // Never an HTTP call inside a transaction.
        $payload = [
            'caption' => $validated['caption'],
            'social_accounts' => $accounts->pluck('provider_account_id')->all(),
            'external_id' => $ulid,
        ];

        if (isset($validated['media'])) {
            $payload['media'] = $validated['media'];
        }

        if ($scheduledAt !== null) {
            $payload['scheduled_at'] = $scheduledAt->toIso8601String();
        }

        if ($configurations->isNotEmpty()) {
            $payload['account_configurations'] = $configurations->all();
        }

        // Recorded and paid for first, then handed to the provider: a short
        // balance — or a teammate spending the same wallet a moment earlier —
        // refuses here, before anything can go live unpaid. A held post or a
        // draft is only recorded; approving or sending it reaches the provider.
        $post = DB::transaction(function () use ($user, $ulid, $validated, $accounts, $captions, $scheduledAt, $approval, $draft, $type, $sendsNow) {
            $post = $user->socialPosts()->create([
                'ulid' => $ulid,
                'caption' => $validated['caption'],
                'type' => $type,
                'labels' => $validated['labels'] ?? null,
                'media' => $validated['media'] ?? null,
                'scheduled_at' => $scheduledAt,
                'approval' => $approval,
                'status' => $draft ? SocialPostStatus::Draft : SocialPostStatus::Scheduled,
            ]);

            foreach ($accounts as $account) {
                $post->targets()->create([
                    'social_account_id' => $account->id,
                    'configuration' => isset($captions[$account->ulid])
                        ? ['caption' => $captions[$account->ulid]]
                        : null,
                    'status' => SocialPostTargetStatus::Pending,
                ]);
            }

            // A destination that fails or is cancelled hands its publish back.
            if ($sendsNow) {
                Credits::chargeSend($post);
            }

            return $post;
        });

        if ($sendsNow) {
            try {
                $remote = $provider->createPost($payload);
            } catch (Throwable $e) {
                // Nothing went out: give the publishes back and forget the row.
                DB::transaction(function () use ($post) {
                    Credits::returnSend($post, $post->targets()->get(), 'Not sent · the provider refused it');
                    $post->delete();
                });

                throw $e;
            }

            // `processed` means it already went out — anything else is on its way.
            $post->update([
                'provider_post_id' => (string) $remote['id'],
                'status' => ($remote['status'] ?? null) === 'processed'
                    ? SocialPostStatus::Published
                    : SocialPostStatus::Scheduled,
            ]);
        }

        ProductEvents::postCreated($post, match (true) {
            $draft => 'draft',
            $approval !== null => 'approval',
            $scheduledAt !== null => 'scheduled',
            default => 'now',
        });

        return (new SocialPostResource($post->load('targets.account')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Reschedule or re-caption a post that has not gone out. Only a post
     * with a future slot qualifies — an immediate one is already in flight.
     * Destinations and media stay as sent; the provider's PUT is a full
     * replace, so the body is rebuilt from the row.
     */
    public function update(
        Request $request,
        SocialPost $socialPost,
        SocialProviders $providers,
        SocialPostRelay $relay,
    ): SocialPostResource|JsonResponse {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $validated = $request->validate([
            'caption' => ['sometimes', 'string', 'max:63206'],
            'scheduled_at' => ['sometimes', 'date', 'after:now'],
            // Labels are ours alone — never sent to the provider.
            'labels' => ['sometimes', 'nullable', 'array', 'max:10'],
            'labels.*' => ['string', 'max:40'],
        ]);

        // Through a connected app or API key, only a draft or a post still
        // waiting for approval changes: nothing already at the provider, on
        // its way out, or history. The app keeps its own rules below.
        if (SendConfirmation::viaToken($request)
            && ! ($socialPost->status === SocialPostStatus::Draft
                || ($socialPost->status === SocialPostStatus::Scheduled && $socialPost->held()))) {
            return response()->json([
                'message' => 'Connected apps can only change drafts and posts still waiting for approval. Edit this one in creatif.space.',
                'code' => 'not_editable',
            ], 409);
        }

        // Labels alone are our own filing: any post, any status, no provider.
        if (array_keys($validated) === ['labels']) {
            $socialPost->update(['labels' => $validated['labels']]);

            return new SocialPostResource($socialPost->load('targets.account'));
        }

        // A held post or a draft is still ours, so it can change even with
        // no slot; a sent one only while its slot is ahead.
        $ours = $socialPost->held() || $socialPost->status === SocialPostStatus::Draft;

        if (! in_array($socialPost->status, [SocialPostStatus::Scheduled, SocialPostStatus::Draft], true)
            || (! $ours && ($socialPost->scheduled_at === null || $socialPost->scheduled_at->isPast()))) {
            return response()->json([
                'message' => 'Only posts still waiting for their slot can be changed.',
            ], 422);
        }

        $caption = $validated['caption'] ?? $socialPost->caption;
        $scheduledAt = isset($validated['scheduled_at'])
            ? Carbon::parse($validated['scheduled_at'])
            : $socialPost->scheduled_at;

        // Provider first: the row only changes once the plan really moved.
        if ($socialPost->provider_post_id !== null) {
            SocialPostRelay::ensureUsable($socialPost);
            $providers->for($socialPost)->updatePost(
                $socialPost->provider_post_id,
                $relay->payload($socialPost, $caption, $scheduledAt),
            );
        }

        $from = $socialPost->scheduled_at;
        $socialPost->update(['caption' => $caption, 'scheduled_at' => $scheduledAt]
            + (array_key_exists('labels', $validated) ? ['labels' => $validated['labels']] : []));

        // The move's record: who moved which post, from when to when. A
        // caption-only edit is not a move and leaves no line.
        if ($scheduledAt !== null && ($from === null || ! $from->equalTo($scheduledAt))) {
            Activity::log(
                $request->user(),
                ActivityAction::PostReschedule,
                'Moved a post to '.$scheduledAt->format('D j M, H:i'),
                'social_post',
                $socialPost->ulid,
                ['from' => $from?->toIso8601String(), 'to' => $scheduledAt->toIso8601String()],
            );
        }

        return new SocialPostResource($socialPost->load('targets.account'));
    }

    /**
     * Trade two posts' slots — Grid's drag. One request so it is one act:
     * if the second provider replace fails, the first is put back before
     * answering, and the rows only change once both landed.
     */
    public function swap(Request $request, SocialProviders $providers, SocialPostRelay $relay): JsonResponse
    {
        $validated = $request->validate([
            'a' => ['required', 'string'],
            'b' => ['required', 'string', 'different:a'],
        ]);

        $posts = $request->user()->socialPosts()
            ->whereIn('ulid', [$validated['a'], $validated['b']])
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now())
            ->get()
            ->keyBy('ulid');

        if ($posts->count() !== 2) {
            return response()->json(['message' => 'Both posts must be yours and still waiting for a future slot.'], 422);
        }

        [$a, $b] = [$posts[$validated['a']], $posts[$validated['b']]];
        foreach ([$a, $b] as $p) {
            if ($p->provider_post_id !== null) {
                SocialPostRelay::ensureUsable($p);
            }
        }
        [$atA, $atB] = [$a->scheduled_at, $b->scheduled_at];

        $replace = fn (SocialPost $p, $at) => $p->provider_post_id === null ? null
            : $providers->for($p)->updatePost($p->provider_post_id, $relay->payload($p, null, $at));

        $replace($a, $atB);
        try {
            $replace($b, $atA);
        } catch (Throwable $e) {
            $replace($a, $atA); // put the first back; if this throws too, the error says so
            throw $e;
        }

        DB::transaction(function () use ($a, $b, $atA, $atB) {
            $a->update(['scheduled_at' => $atB]);
            $b->update(['scheduled_at' => $atA]);
        });

        foreach ([[$a, $atA, $atB], [$b, $atB, $atA]] as [$p, $from, $to]) {
            Activity::log($request->user(), ActivityAction::PostReschedule, 'Moved a post to '.$to->format('D j M, H:i'),
                'social_post', $p->ulid, ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'swap' => true]);
        }

        return response()->json(null, 204);
    }

    /**
     * What sending this draft would do, without doing it: each destination
     * and its Credit price, the total, and whether the wallet covers it.
     * API clients show this before asking the user to confirm a send.
     */
    public function sendPreview(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $user = $request->user();
        $socialPost->load('targets.account');

        $targets = $socialPost->targets->map(function ($target) use ($socialPost) {
            $caption = $target->configuration['caption'] ?? $socialPost->caption;

            return [
                'channel_id' => $target->account->ulid,
                'platform' => $target->account->platform,
                'username' => $target->account->username,
                'usable' => $target->account->isUsable(),
                'credits' => Credits::perSend($target->account->platform, $caption),
                'destination' => [$target->account->platform, $caption],
            ];
        });

        $destinations = $targets->pluck('destination')->all();
        $price = (int) $targets->sum('credits');
        $past = Credits::sendPrice($user, $destinations);

        return response()->json(['data' => [
            'post_id' => $socialPost->ulid,
            'sendable' => $socialPost->status === SocialPostStatus::Draft,
            'status' => $socialPost->status,
            'scheduled_at' => $socialPost->scheduled_at?->toIso8601String(),
            'targets' => $targets->map(fn ($t) => array_diff_key($t, ['destination' => true]))->values()->all(),
            'credits' => [
                'price' => $price,
                // What the send takes from Top-up once Monthly Credits are used.
                'from_topup' => $past,
                'monthly_balance' => Credits::monthlyBalance($user),
                'topup_balance' => Credits::balance($user),
                'can_afford' => PlanQuota::canPostSocial($user, $destinations),
            ],
            'daily_cap' => CapTokenSends::status($user),
            // A send through a connected app or API key must bring this back
            // (10 minutes; bound to these destinations and this price).
            ...($socialPost->status === SocialPostStatus::Draft ? [
                'confirm_token' => SendConfirmation::issue($user, $socialPost),
                'confirm_expires_at' => now()->addMinutes(SendConfirmation::TTL_MINUTES)->toIso8601String(),
            ] : []),
        ]]);
    }

    /**
     * Send a draft: the quota gate runs now, then it goes to the provider —
     * or is held, if it asks for approval.
     */
    public function send(Request $request, SocialPost $socialPost, SocialPostRelay $relay): SocialPostResource|JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $validated = $request->validate([
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'approval' => ['sometimes', 'nullable', Rule::in(['awaiting', 'client'])],
            'confirm_token' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);

        if ($socialPost->status !== SocialPostStatus::Draft) {
            return response()->json(['message' => 'Only a draft can be sent from here.'], 422);
        }

        // Approval is kept, whoever sends: a draft that needs its client's
        // yes (or whose client asked for changes) can only be held for the
        // client again — never sent, scheduled, or quietly downgraded to
        // the editor's own approval. A connected app cannot drop the
        // editor's own approval step either; the editor in the app can.
        $asked = $validated['approval'] ?? null;
        $needsClient = in_array($socialPost->approval, [SocialPostApproval::Client, SocialPostApproval::Revision], true);
        $needsEditor = $socialPost->approval === SocialPostApproval::Awaiting;
        if (($needsClient && $asked !== SocialPostApproval::Client->value)
            || ($needsEditor && $asked === null && SendConfirmation::viaToken($request))) {
            return response()->json([
                'message' => $needsClient
                    ? 'This draft needs its client\'s approval before it can be sent or scheduled. Share it on an approval link.'
                    : 'This draft is waiting for approval. Approve it in creatif.space, or send it for client approval.',
                'code' => 'approval_required',
            ], 409);
        }

        // A connected app or API key sends only what the user saw in the
        // send preview: same destinations, same price, at most 10 minutes
        // ago. Holding the draft for approval sends nothing and needs none.
        if (! isset($validated['approval']) && SendConfirmation::viaToken($request)) {
            if (empty($validated['confirm_token'])) {
                return response()->json([
                    'message' => 'Sending from a connected app needs the confirm_token from GET /social-posts/{post}/send-preview.',
                    'code' => 'confirm_token_required',
                ], 422);
            }
            if (! SendConfirmation::matches($request->user(), $socialPost, $validated['confirm_token'])) {
                return response()->json([
                    'message' => 'The preview is out of date (channels or Credits changed, or 10 minutes passed). Show the user a new preview and confirm again.',
                    'code' => 'preview_stale',
                ], 409);
            }
        }

        if (($validated['approval'] ?? null) === SocialPostApproval::Client->value) {
            PlanQuota::requireClientApprovals($request->user());
        }

        // The relay charges as it sends, and refuses before the provider if
        // the balance is short; a held draft costs nothing yet.
        $socialPost->update([
            'scheduled_at' => isset($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null,
            'approval' => isset($validated['approval']) ? SocialPostApproval::from($validated['approval']) : null,
        ]);

        if ($socialPost->held()) {
            $socialPost->update(['status' => SocialPostStatus::Scheduled]);
        } else {
            $relay->send($socialPost);
        }

        return new SocialPostResource($socialPost->fresh()->load('targets.account'));
    }

    /**
     * Throw a draft away. It was never sent, so there is nothing to cancel.
     */
    public function discard(Request $request, SocialPost $socialPost): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        if ($socialPost->status !== SocialPostStatus::Draft) {
            return response()->json(['message' => 'Only a draft can be discarded.'], 422);
        }

        $socialPost->delete();

        return response()->json(null, 204);
    }

    /**
     * Cancel a scheduled post. The Credits taken for destinations that had
     * not gone out come back. Anything past Scheduled is history, not a
     * plan, and history cannot be cancelled.
     */
    public function destroy(
        Request $request,
        SocialPost $socialPost,
        SocialProviders $providers,
    ): JsonResponse {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        // A failed post never went out and its publishes already came back
        // with the failure: nothing to cancel, so it is simply removed.
        if ($socialPost->status === SocialPostStatus::Failed) {
            $socialPost->delete();

            return response()->json(null, 204);
        }

        if ($socialPost->status !== SocialPostStatus::Scheduled) {
            return response()->json([
                'message' => 'Only scheduled posts can be cancelled.',
            ], 422);
        }

        $sent = $socialPost->provider_post_id !== null;
        if ($sent) {
            $providers->for($socialPost)->deletePost($socialPost->provider_post_id);
        }

        DB::transaction(function () use ($socialPost, $sent) {
            $socialPost->update(['status' => SocialPostStatus::Cancelled]);

            // Only a post that was handed over was charged.
            if ($sent) {
                Credits::returnSend(
                    $socialPost,
                    $socialPost->targets()->where('status', SocialPostTargetStatus::Pending)->get(),
                    'Cancelled before it went out',
                );
            }
        });

        return response()->json(null, 204);
    }

    /**
     * Library files as media entries: ready files of this workspace only.
     *
     * @param  list<string>  $ids
     * @return list<array{url: string}>
     */
    private function libraryMedia(User $user, array $ids): array
    {
        $files = File::query()
            ->whereIn('ulid', $ids)
            ->where('user_id', Workspace::owner($user)->id)
            ->where('status', File::STATUS_READY)
            ->get()
            ->keyBy('ulid');

        if ($files->count() !== count($ids)) {
            throw ValidationException::withMessages(['file_ids' => 'One or more files are not ready files in your library.']);
        }

        return array_map(fn (string $id) => ['url' => $files[$id]->url()], $ids);
    }
}
