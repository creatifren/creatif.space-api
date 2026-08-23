<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Models\SocialPost;
use App\Services\PostForMeService;
use App\Support\PlanQuota;
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
                ->with('targets.account')
                ->latest()
                ->get(),
        )->additional(['meta' => [
            // The composer's "x of y left this month" line. null limit = no cap.
            'posts_used' => PlanQuota::socialPostsUsed($request->user()),
            'posts_limit' => PlanQuota::socialPostsLimit($request->user()),
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
        ]);

        $user = $request->user();

        // Quota gate before anything reaches the provider. Free's limit is
        // zero, so this is also the "social is a paid feature" wall.
        if (! PlanQuota::canPostSocial($user)) {
            $limit = PlanQuota::socialPostsLimit($user);

            throw ValidationException::withMessages([
                'caption' => $limit === 0
                    ? 'Social posting is not included on the Free plan — upgrade to Premium to publish.'
                    : "You've reached your {$limit} posts for this month.",
            ]);
        }

        $ids = array_values(array_unique($validated['account_ids']));

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

        $configurations = $accounts
            ->filter(fn ($a) => isset($captions[$a->ulid]))
            ->map(fn ($a) => [
                'social_account_id' => $a->provider_account_id,
                'configuration' => ['caption' => $captions[$a->ulid]],
            ])
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

        $remote = $postForMe->createPost($payload);

        $post = DB::transaction(function () use ($user, $ulid, $validated, $accounts, $captions, $scheduledAt, $remote) {
            $post = $user->socialPosts()->create([
                'ulid' => $ulid,
                'provider_post_id' => (string) $remote['id'],
                'caption' => $validated['caption'],
                'media' => $validated['media'] ?? null,
                'scheduled_at' => $scheduledAt,
                // `processed` means it already went out — everything else is
                // still on its way.
                'status' => ($remote['status'] ?? null) === 'processed'
                    ? SocialPostStatus::Published
                    : SocialPostStatus::Scheduled,
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

            return $post;
        });

        return (new SocialPostResource($post->load('targets.account')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Cancel a scheduled post. Cancelling uses nothing — the quota moves
     * when a post goes out. Anything past Scheduled is history, not a plan,
     * and history cannot be cancelled.
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

        if ($socialPost->provider_post_id !== null) {
            $postForMe->deletePost($socialPost->provider_post_id);
        }

        $socialPost->update(['status' => SocialPostStatus::Cancelled]);

        return response()->json(null, 204);
    }
}
