<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\PostForMeService;
use App\Support\Credits;
use App\Support\SocialPostRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SocialAccountController extends Controller
{
    /**
     * The user's connected social accounts (the Connections board).
     * Grouping by platform is the client's job.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SocialAccountResource::collection(
            $request->user()->socialAccounts()
                ->orderBy('platform')
                ->orderBy('username')
                ->get(),
        );
    }

    /**
     * Where the user goes to sign in and grant an account. The external_id
     * is our user's ulid — it is what makes the shared Post for Me
     * workspace multi-tenant.
     */
    public function authUrl(Request $request, PostForMeService $postForMe): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', Rule::enum(SocialPlatform::class)],
            // Bluesky has no OAuth page: it signs in with a handle and an app password.
            'handle' => ['required_if:platform,bluesky', 'nullable', 'string', 'max:253'],
            'app_password' => ['required_if:platform,bluesky', 'nullable', 'string', 'max:64'],
        ]);

        $url = $postForMe->authUrl(
            $validated['platform'],
            $request->user()->ulid,
            $validated['platform'] === 'bluesky'
                ? ['handle' => ltrim($validated['handle'], '@'), 'app_password' => $validated['app_password']]
                : [],
        );

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * Reconcile local rows against the provider. Called by the frontend
     * after the OAuth redirect returns; also the safety net for missed
     * webhooks. Accounts connected without our external_id (e.g. via the
     * provider dashboard) are invisible here — accepted for now.
     */
    public function sync(Request $request, PostForMeService $postForMe): AnonymousResourceCollection
    {
        $user = $request->user();
        $remote = $postForMe->accountsFor($user->ulid);

        $seen = [];

        foreach ($remote as $account) {
            $platform = SocialPlatform::tryFrom((string) ($account['platform'] ?? ''));
            $providerId = (string) ($account['id'] ?? '');

            if ($platform === null || $providerId === '') {
                continue;
            }

            $seen[] = $providerId;

            $user->socialAccounts()->updateOrCreate(
                [
                    'platform' => $platform,
                    'provider_account_id' => $providerId,
                ],
                [
                    'username' => $account['username'] ?? null,
                    'profile_photo_url' => $account['profile_photo_url'] ?? null,
                    'status' => ($account['status'] ?? 'connected') === 'connected'
                        ? SocialAccountStatus::Connected
                        : SocialAccountStatus::Disconnected,
                    'connected_at' => now(),
                ],
            );
        }

        // A row the provider no longer lists lost its grant.
        $user->socialAccounts()
            ->whereNotIn('provider_account_id', $seen)
            ->where('status', SocialAccountStatus::Connected)
            ->update(['status' => SocialAccountStatus::Disconnected]);

        return SocialAccountResource::collection(
            $user->socialAccounts()->orderBy('platform')->orderBy('username')->get(),
        );
    }

    /**
     * Disconnect an account. The row stays as Disconnected — published
     * posts and their numbers reference it. A post still to go loses this
     * destination (its 3 Credits come back); one aimed only here goes back
     * to being a draft, pulled from the provider and off any approval link.
     */
    public function destroy(
        Request $request,
        SocialAccount $socialAccount,
        PostForMeService $postForMe,
        SocialPostRelay $relay,
    ): JsonResponse {
        Gate::allowIf(fn ($user) => $socialAccount->user_id === $user->id);

        $why = 'Its account was disconnected';
        $posts = SocialPost::query()
            ->where('user_id', $socialAccount->user_id)
            ->whereIn('status', [SocialPostStatus::Scheduled, SocialPostStatus::Draft])
            ->whereHas('targets', fn ($q) => $q->where('social_account_id', $socialAccount->id))
            ->withCount('targets')
            ->with('user')
            ->get();

        foreach ($posts as $post) {
            if ($post->targets_count === 1) {
                $relay->recall($post, $why);
                $post->targets()->delete();
                DB::table('social_approval_link_post')->where('social_post_id', $post->id)->delete();
                $post->update([
                    'status' => SocialPostStatus::Draft,
                    'approval' => null,
                    'approval_by' => null,
                    'approval_at' => null,
                ]);

                continue;
            }

            $target = $post->targets()->where('social_account_id', $socialAccount->id)->first();
            $target->delete();
            if ($post->provider_post_id !== null) {
                $postForMe->updatePost($post->provider_post_id, $relay->payload($post));
                if ($target->status === SocialPostTargetStatus::Pending) {
                    Credits::returnSend($post, 1, $why);
                }
            }
        }

        $postForMe->disconnect($socialAccount->provider_account_id);
        $postForMe->deleteAccount($socialAccount->provider_account_id);

        $socialAccount->update(['status' => SocialAccountStatus::Disconnected]);

        return response()->json(null, 204);
    }
}
