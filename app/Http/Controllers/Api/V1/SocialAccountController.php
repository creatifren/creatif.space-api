<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OutstandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
     * Where the user goes to sign in and grant an account. X needs our own
     * developer app on Outstand and Bluesky an app-password form — neither
     * exists yet, so both are refused here.
     */
    public function authUrl(Request $request, OutstandService $outstand): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', Rule::enum(SocialPlatform::class)
                ->except([SocialPlatform::X, SocialPlatform::Bluesky])],
        ]);

        $url = $outstand->connectUrl($request->user(), SocialPlatform::from($validated['platform']));

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * The browser lands here after granting. Public by necessity — it comes
     * back from Outstand, not from our SPA — so the single-use nonce is the
     * authentication, and the account id is checked against the org before
     * it is believed.
     */
    public function callback(Request $request, string $nonce, OutstandService $outstand): RedirectResponse
    {
        $pending = $outstand->pullNonce($nonce);
        $accountId = (string) $request->query('account_id', '');

        if ($pending === null || $request->filled('error') || $accountId === '') {
            return $this->backToConnect('error');
        }

        $remote = collect($outstand->accounts())
            ->first(fn (array $a) => (string) ($a['id'] ?? '') === $accountId);

        // The grant must be the platform that was asked for, and must not
        // already belong to somebody else — one org holds everyone's accounts.
        $claimedElsewhere = SocialAccount::query()
            ->where('provider_account_id', $accountId)
            ->where('user_id', '!=', $pending['user_id'])
            ->where('status', SocialAccountStatus::Connected)
            ->exists();

        if ($remote === null || ($remote['network'] ?? null) !== $pending['platform'] || $claimedElsewhere) {
            return $this->backToConnect('error');
        }

        User::query()->findOrFail($pending['user_id'])->socialAccounts()->updateOrCreate(
            [
                'platform' => $pending['platform'],
                'provider_account_id' => $accountId,
            ],
            [
                'username' => $remote['username'] ?? $request->query('username'),
                'status' => SocialAccountStatus::Connected,
                'connected_at' => now(),
            ],
        );

        return $this->backToConnect('connected');
    }

    /**
     * Reconcile the user's own rows against the org. Only ever updates —
     * the org lists everyone's accounts, so a row is created by the connect
     * callback and nowhere else.
     */
    public function sync(Request $request, OutstandService $outstand): AnonymousResourceCollection
    {
        $user = $request->user();

        $active = collect($outstand->accounts())
            ->filter(fn (array $a) => ($a['isActive'] ?? true) === true)
            ->map(fn (array $a) => (string) ($a['id'] ?? ''))
            ->all();

        // A row the org no longer lists as active lost its grant.
        $user->socialAccounts()
            ->whereNotIn('provider_account_id', $active)
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
    public function destroy(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        Gate::allowIf(fn ($user) => $socialAccount->user_id === $user->id);

        // ponytail: Outstand has no disconnect endpoint, so the grant stays in
        // the org until revoked by hand on their dashboard. Our side stops
        // using it; add the API call if Outstand ships one.
        $socialAccount->update(['status' => SocialAccountStatus::Disconnected]);

        return response()->json(null, 204);
    }

    private function backToConnect(string $outcome): RedirectResponse
    {
        return redirect(config('app.frontend_url')."/social/connect?social={$outcome}");
    }
}
