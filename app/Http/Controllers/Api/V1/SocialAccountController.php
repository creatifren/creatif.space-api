<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Services\PostForMeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        ]);

        $url = $postForMe->authUrl($validated['platform'], $request->user()->ulid);

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
     * posts and their numbers reference it. Scheduled posts to it are left
     * for the provider to fail at publish time; the webhook records why.
     */
    public function destroy(
        Request $request,
        SocialAccount $socialAccount,
        PostForMeService $postForMe,
    ): JsonResponse {
        Gate::allowIf(fn ($user) => $socialAccount->user_id === $user->id);

        $postForMe->disconnect($socialAccount->provider_account_id);
        $postForMe->deleteAccount($socialAccount->provider_account_id);

        $socialAccount->update(['status' => SocialAccountStatus::Disconnected]);

        return response()->json(null, 204);
    }
}
