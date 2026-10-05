<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPostApproval;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\Activity;
use App\Models\SocialPost;
use App\Services\PostForMeService;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\SocialPostRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialPostController extends Controller
{
    /**
     * The user's posts — Scheduled and Published tabs are one endpoint with
     * a status filter.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(SocialPostStatus::class)],
        ]);

        return SocialPostResource::collection(
            $request->user()->socialPosts()
                ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                // Threads ride along: the Feed shows every post's conversation
                // at once, so one request beats one per card.
                ->with(['targets.account', 'comments.replies'])
                ->latest()
                ->get(),
        )->additional(['meta' => [
            // The composer's "uses N of your Credits" line.
            'credits' => Credits::balance($request->user()),
            'per_send' => Credits::PER_SEND,
            // Kept for the usage screen's monthly count.
            'posts_used' => PlanQuota::socialPostsUsed($request->user()),
            'posts_limit' => null,
        ]]);
    }

    /**
     * Create (and optionally schedule) a post. The ownership check on
     * account_ids is the tenant boundary: only the user's own Connected
     * accounts resolve to provider ids, and nothing is sent otherwise.
     */
    public function store(Request $request, PostForMeService $postForMe): JsonResponse
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
        $approval = isset($validated['approval']) ? SocialPostApproval::from($validated['approval']) : null;
        $draft = (bool) ($validated['draft'] ?? false);
        $ids = array_values(array_unique($validated['account_ids']));
        $sendsNow = $approval === null && ! $draft;

        // The price is 3 Credits a destination, checked before anything
        // reaches the provider. Held posts and drafts are checked when they
        // are approved or sent — holding one costs nothing.
        if ($sendsNow && ! PlanQuota::canPostSocial($user, count($ids))) {
            $need = count($ids) * Credits::PER_SEND;

            throw ValidationException::withMessages([
                'caption' => "This needs {$need} Credits and the balance has ".Credits::balance($user)
                    .' — top up, or send to fewer accounts.',
            ]);
        }

        $accounts = $user->socialAccounts()
            ->whereIn('ulid', $ids)
            ->where('status', SocialAccountStatus::Connected)
            ->get();

        if ($accounts->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'account_ids' => 'One or more accounts are not connected accounts of yours.',
            ]);
        }

        $captions = $validated['captions'] ?? [];
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

        // Provider first, DB second — never an HTTP call inside the
        // transaction, and nothing is recorded that was never sent.
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

        // A held post or a draft is only recorded; approving or sending it
        // is what reaches the provider.
        $remote = $sendsNow ? $postForMe->createPost($payload) : null;

        $post = DB::transaction(function () use ($user, $ulid, $validated, $accounts, $captions, $scheduledAt, $remote, $approval, $draft, $type) {
            $post = $user->socialPosts()->create([
                'ulid' => $ulid,
                'provider_post_id' => $remote ? (string) $remote['id'] : null,
                'caption' => $validated['caption'],
                'type' => $type,
                'labels' => $validated['labels'] ?? null,
                'media' => $validated['media'] ?? null,
                'scheduled_at' => $scheduledAt,
                'approval' => $approval,
                // `processed` means it already went out — everything else is
                // still on its way.
                'status' => $draft
                    ? SocialPostStatus::Draft
                    : (($remote['status'] ?? null) === 'processed'
                        ? SocialPostStatus::Published
                        : SocialPostStatus::Scheduled),
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

            // Charged with the row it pays for; a destination that fails
            // or is cancelled hands its 3 back.
            if ($remote !== null) {
                Credits::chargeSend($post, $accounts->count());
            }

            return $post;
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
        PostForMeService $postForMe,
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
            $postForMe->updatePost(
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
     * Send a draft: the quota gate runs now, then it goes to the provider —
     * or is held, if it asks for approval.
     */
    public function send(Request $request, SocialPost $socialPost, SocialPostRelay $relay): SocialPostResource|JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        $validated = $request->validate([
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'approval' => ['sometimes', 'nullable', Rule::in(['awaiting', 'client'])],
        ]);

        if ($socialPost->status !== SocialPostStatus::Draft) {
            return response()->json(['message' => 'Only a draft can be sent from here.'], 422);
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
        PostForMeService $postForMe,
    ): JsonResponse {
        Gate::allowIf(fn ($user) => $socialPost->user_id === $user->id);

        if ($socialPost->status !== SocialPostStatus::Scheduled) {
            return response()->json([
                'message' => 'Only scheduled posts can be cancelled.',
            ], 422);
        }

        $sent = $socialPost->provider_post_id !== null;
        if ($sent) {
            $postForMe->deletePost($socialPost->provider_post_id);
        }

        DB::transaction(function () use ($socialPost, $sent) {
            $socialPost->update(['status' => SocialPostStatus::Cancelled]);

            // Only a post that was handed over was charged.
            if ($sent) {
                Credits::returnSend(
                    $socialPost,
                    $socialPost->targets()->where('status', SocialPostTargetStatus::Pending)->count(),
                    'Cancelled before it went out',
                );
            }
        });

        return response()->json(null, 204);
    }
}
