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
use App\Models\User;
use App\Services\PostForMeService;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\SocialPostRelay;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        $this->ensureRoom($request->user(), 'platform');

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
     * Claim an account another Creatif Space user holds. The provider keeps
     * one owner per platform account and refuses a second, so the claimant
     * proves it by signing in again: the account is parked under a one-off
     * tag for the trip (the provider would refuse it under the claimant's
     * own id), and `sync` moves it only if that sign-in renewed its token.
     * The error URL that names the account is unsigned — it decides nothing.
     */
    public function claim(Request $request, PostForMeService $postForMe): JsonResponse
    {
        $validated = $request->validate(['account_id' => ['required', 'string', 'max:64']]);
        $user = $request->user();

        $remote = $postForMe->account($validated['account_id']);
        $platform = SocialPlatform::tryFrom((string) ($remote['platform'] ?? ''));
        $owner = (string) ($remote['external_id'] ?? '');

        if (str_starts_with($owner, self::CLAIM)) {
            $pending = Cache::get("social-claim:{$owner}");

            if ($pending === null || $pending['until'] > now()->timestamp) {
                throw ValidationException::withMessages([
                    'account_id' => 'Someone is claiming this account right now. Try again in a few minutes.',
                ]);
            }

            // An abandoned claim: take over from it, for the same owner.
            Cache::forget("social-claim:{$owner}");
            $owner = $pending['owner'];
        }

        // Bluesky signs in with an app password, not a trip — nothing to renew.
        if ($platform === null || $platform === SocialPlatform::Bluesky || $owner === '' || $owner === $user->ulid) {
            throw ValidationException::withMessages(['account_id' => 'This account cannot be claimed.']);
        }

        $this->ensureRoom($user, 'account_id');

        $tag = self::CLAIM.Str::random(24);
        $postForMe->assignAccount($validated['account_id'], $tag);

        try {
            $url = $postForMe->authUrl($platform->value, $tag);
        } catch (Throwable $e) {
            $postForMe->assignAccount($validated['account_id'], $owner);

            throw $e;
        }

        // No TTL: the record is the only way back to the owner, so it lives
        // until the claim finishes or the owner's sync undoes it.
        Cache::forever("social-claim:{$tag}", [
            'claimant' => $user->id,
            'owner' => $owner,
            'account_id' => $validated['account_id'],
            'token' => hash('sha256', (string) ($remote['access_token'] ?? '')),
            'until' => now()->addMinutes(self::CLAIM_MINUTES)->timestamp,
        ]);
        Cache::forever("social-claim-by:{$user->id}", $tag);

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
        $this->finishClaim($user, $postForMe);
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

        // A row the provider no longer lists lost its grant — unless it is
        // only parked for someone's claim: that one comes back if the claim
        // goes nowhere.
        $user->socialAccounts()
            ->whereNotIn('provider_account_id', $seen)
            ->where('status', SocialAccountStatus::Connected)
            ->get()
            ->reject(fn (SocialAccount $a) => $this->parked($a->provider_account_id, $user, $postForMe))
            ->each(fn (SocialAccount $a) => $a->update(['status' => SocialAccountStatus::Disconnected]));

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

            // The provider stops the destination first; only then is it
            // refunded and forgotten. A failed update keeps it charged and
            // matchable by its result webhook.
            if ($post->provider_post_id !== null) {
                $payload = $relay->payload($post);
                $leaving = $socialAccount->provider_account_id;
                $payload['social_accounts'] = array_values(array_diff($payload['social_accounts'], [$leaving]));
                if (isset($payload['account_configurations'])) {
                    $payload['account_configurations'] = array_values(array_filter(
                        $payload['account_configurations'],
                        fn (array $c) => $c['social_account_id'] !== $leaving,
                    ));
                }
                $postForMe->updatePost($post->provider_post_id, $payload);
            }

            DB::transaction(function () use ($post, $target, $why) {
                if ($post->provider_post_id !== null && $target->status === SocialPostTargetStatus::Pending) {
                    Credits::returnSend($post, [$target], $why);
                }
                $target->delete();
            });
        }

        $postForMe->disconnect($socialAccount->provider_account_id);
        $postForMe->deleteAccount($socialAccount->provider_account_id);

        $socialAccount->update(['status' => SocialAccountStatus::Disconnected]);

        return response()->json(null, 204);
    }

    /** Prefix of the one-off tag an account wears while it is being claimed. */
    private const CLAIM = 'claim_';

    /** How long the claimant has to finish signing in. */
    private const CLAIM_MINUTES = 15;

    /**
     * Refused before the provider's consent page, where a refusal would
     * otherwise surface as an account that connected and then vanished.
     */
    private function ensureRoom(User $user, string $field): void
    {
        if (PlanQuota::canConnectSocialAccount($user)) {
            return;
        }

        $limit = Workspace::owner($user)->plan()->quota('social_accounts');

        throw ValidationException::withMessages([
            $field => "Free connects up to {$limit} social accounts. Upgrade to Pro or Max to add more.",
        ]);
    }

    /**
     * The claimant is back from signing in. The claimed account moves to
     * them only if its token changed under the claim tag — that is the
     * sign-in we asked for. Anything else goes back to its owner. Other
     * accounts the same sign-in brought along are the claimant's own.
     */
    private function finishClaim(User $user, PostForMeService $postForMe): void
    {
        $tag = Cache::get("social-claim-by:{$user->id}");
        $claim = $tag ? Cache::get("social-claim:{$tag}") : null;

        if ($claim === null || $claim['claimant'] !== $user->id) {
            Cache::forget("social-claim-by:{$user->id}");

            return;
        }

        foreach ($postForMe->accountsFor($tag) as $account) {
            $id = (string) ($account['id'] ?? '');

            if ($id === '') {
                continue;
            }

            // The claimed one is read on its own: an empty token from a list
            // that leaves it out must never pass as "changed".
            $token = $id === $claim['account_id']
                ? (string) ($postForMe->account($id)['access_token'] ?? '')
                : '';
            $proven = $id !== $claim['account_id']
                || ($token !== '' && hash('sha256', $token) !== $claim['token']);

            $postForMe->assignAccount($id, $proven ? $user->ulid : $claim['owner']);

            if ($proven && $id === $claim['account_id']) {
                // The old owner's row stays, Disconnected — their published
                // posts reference it.
                SocialAccount::query()
                    ->where('provider_account_id', $id)
                    ->where('user_id', '!=', $user->id)
                    ->update(['status' => SocialAccountStatus::Disconnected]);
            }
        }

        // Forgotten only once every account is re-tagged: a provider hiccup
        // above leaves the claim for the next sync to finish.
        Cache::forget("social-claim:{$tag}");
        Cache::forget("social-claim-by:{$user->id}");
    }

    /**
     * Is this account only away on a claim? An abandoned claim is undone
     * here, on the owner's next sync, so the account cannot stay parked.
     */
    private function parked(string $accountId, User $owner, PostForMeService $postForMe): bool
    {
        $tag = (string) ($postForMe->account($accountId)['external_id'] ?? '');
        $claim = str_starts_with($tag, self::CLAIM) ? Cache::get("social-claim:{$tag}") : null;

        if ($claim === null || $claim['owner'] !== $owner->ulid) {
            return false;
        }

        if ($claim['until'] <= now()->timestamp) {
            Cache::forget("social-claim:{$tag}");
            $postForMe->assignAccount($accountId, $owner->ulid);
        }

        return true;
    }
}
