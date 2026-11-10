<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SocialProvider;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPostTargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Jobs\RefreshFollowerCount;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\OutstandService;
use App\Support\Credits;
use App\Support\PlanQuota;
use App\Support\ProductEvents;
use App\Support\ProviderFields;
use App\Support\SocialPostRelay;
use App\Support\SocialProviders;
use App\Support\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialAccountController extends Controller
{
    /** How often loading Channels may re-sync with the provider, per user. */
    private const SELF_HEAL_MINUTES = 5;

    /**
     * The user's connected social accounts (the Connections board).
     * Grouping by platform is the client's job.
     */
    public function index(Request $request, SocialProvider $provider): AnonymousResourceCollection
    {
        // Self-heal: a connect whose return never reached us (closed tab,
        // a failed redirect) shows up the next time Channels loads. At most
        // every SELF_HEAL_MINUTES per user, and never at the list's expense.
        $user = $request->user();
        if ((string) config("services.{$provider->name()}.key") !== ''
            && Cache::add('social-self-heal:'.$user->ulid, true, now()->addMinutes(self::SELF_HEAL_MINUTES))) {
            try {
                $this->syncFor($user, $provider);
            } catch (Throwable $e) {
                Log::warning('social.self_heal_failed', ['user' => $user->ulid, 'error' => $e->getMessage()]);
            }
        }

        // Follower counts refresh in the background; this answers with the cached ones.
        RefreshFollowerCount::queueStale($user);

        return SocialAccountResource::collection(self::listed($request->user()));
    }

    /**
     * Channels: one row per network account. A row left over from the same
     * account connected twice (same platform and network id) is hidden
     * while its twin is connected — published posts still point at it, so
     * it is not deleted.
     *
     * @return Collection<int, SocialAccount>
     */
    private static function listed(User $user): Collection
    {
        $rows = $user->socialAccounts()->orderBy('platform')->orderBy('username')->get();

        $live = $rows
            ->filter(fn (SocialAccount $a) => $a->network_unique_id !== null && $a->status === SocialAccountStatus::Connected)
            ->groupBy(fn (SocialAccount $a) => $a->platform->value.':'.$a->network_unique_id);

        return $rows->reject(function (SocialAccount $a) use ($live) {
            if ($a->network_unique_id === null) {
                return false;
            }
            $twins = $live->get($a->platform->value.':'.$a->network_unique_id);

            return $twins !== null && $twins->first()->id !== $a->id;
        })->values();
    }

    /**
     * Where the user goes to sign in and grant an account. The external_id
     * is our user's ulid — it is what makes the provider's shared
     * workspace multi-tenant.
     */
    public function authUrl(Request $request, SocialProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', Rule::enum(SocialPlatform::class)],
            // Bluesky has no OAuth page: it signs in with a handle and an app password.
            'handle' => ['required_if:platform,bluesky', 'nullable', 'string', 'max:253'],
            'app_password' => ['required_if:platform,bluesky', 'nullable', 'string', 'max:64'],
        ]);

        $this->ensureRoom($request->user(), 'platform');

        $url = $provider->authUrl(
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
     *
     * A provider that cannot re-tag (Outstand) gets no parking: the claimant
     * signs in under their own tenant, and that sign-in is the proof.
     */
    public function claim(Request $request, SocialProvider $provider): JsonResponse
    {
        $validated = $request->validate(['account_id' => ['required', 'string', 'max:64']]);
        $user = $request->user();

        $remote = $provider->account($validated['account_id']);
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

        if (! $provider->canReassign()) {
            return response()->json(['data' => ['url' => $provider->authUrl($platform->value, $user->ulid)]]);
        }

        $tag = self::CLAIM.Str::random(24);
        $provider->assignAccount($validated['account_id'], $tag);

        try {
            $url = $provider->authUrl($platform->value, $tag);
        } catch (Throwable $e) {
            $provider->assignAccount($validated['account_id'], $owner);

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
    public function sync(Request $request, SocialProvider $provider): AnonymousResourceCollection
    {
        $user = $request->user();
        $this->syncFor($user, $provider);

        return SocialAccountResource::collection(self::listed($user));
    }

    /**
     * The reconcile behind sync(), for a user without a request: the
     * OAuth return (Outstand connects most networks directly, with no
     * pending session) and the Channels list's self-heal.
     *
     * @param  array<int, array<string, mixed>>|null  $remote  the provider's list, when the caller already has it
     */
    public function syncFor(User $user, SocialProvider $provider, ?array $remote = null): void
    {
        if ($provider->canReassign()) {
            $this->finishClaim($user, $provider);
        }
        $remote ??= $provider->accountsFor($user->ulid);

        $seen = [];
        // An Instagram sign-in that asked for the messages scope just ended.
        $scopeMarker = OutstandService::MESSAGES_SCOPE_MARKER.$user->ulid;
        $askedForMessages = $provider->name() === 'outstand' && Cache::has($scopeMarker);

        // The same network account connected twice (Outstand made two
        // within 150 ms on one Pinterest sign-in) is one channel: the first
        // the relay lists — its oldest — is kept, the rest are not ours.
        $networkIds = [];

        foreach ($remote as $account) {
            $platform = SocialPlatform::tryFrom((string) ($account['platform'] ?? ''));
            $fields = ProviderFields::account($account);

            if ($platform === null || $fields === null) {
                continue;
            }

            if ($fields['network_unique_id'] !== null) {
                $networkKey = $platform->value.':'.$fields['network_unique_id'];
                if (isset($networkIds[$networkKey])) {
                    // A row we already hold for the twin learns whose twin it
                    // is, so Channels can hide it once it is Disconnected below.
                    $user->socialAccounts()
                        ->where('provider', $provider->name())
                        ->where('provider_account_id', $fields['provider_account_id'])
                        ->update(['network_unique_id' => $fields['network_unique_id']]);

                    continue;
                }
                $networkIds[$networkKey] = true;
            }

            $providerId = $fields['provider_account_id'];
            $seen[] = $providerId;

            $status = ($account['status'] ?? 'connected') === 'connected'
                ? SocialAccountStatus::Connected
                : SocialAccountStatus::Disconnected;
            $key = [
                'provider' => $provider->name(),
                'platform' => $platform,
                'provider_account_id' => $providerId,
            ];
            $existing = $user->socialAccounts()->where($key)->first();

            $row = $user->socialAccounts()->updateOrCreate($key, [
                'username' => $fields['username'],
                'profile_photo_url' => $fields['profile_photo_url'],
                'network_unique_id' => $fields['network_unique_id'],
                'status' => $status,
                'connected_at' => self::connectedAt($account, $existing, $status),
            ]);

            $this->recordMessagesScope($row, $account, $askedForMessages);

            if ($row->wasRecentlyCreated) {
                ProductEvents::channelConnected($user, $row);
            }
        }

        if ($askedForMessages) {
            Cache::forget($scopeMarker);
        }

        // A row the provider no longer lists lost its grant — unless it is
        // only parked for someone's claim: that one comes back if the claim
        // goes nowhere. A row another relay holds cannot be posted through
        // this one: it goes too, and is reconnected here.
        $lost = $user->socialAccounts()
            ->where('status', SocialAccountStatus::Connected)
            ->where(fn ($q) => $q->where('provider', '!=', $provider->name())
                ->orWhereNotIn('provider_account_id', $seen))
            ->get()
            ->reject(fn (SocialAccount $a) => $a->provider === $provider->name()
                && $provider->canReassign()
                && $this->parked($a->provider_account_id, $user, $provider))
            ->each(fn (SocialAccount $a) => $a->update(['status' => SocialAccountStatus::Disconnected]));

        if ($lost->isNotEmpty()) {
            ProductEvents::channelsCount($user);
        }

        // A new or reconnected account gets its follower count in the background.
        RefreshFollowerCount::queueStale($user);
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
        SocialProviders $providers,
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
                // A relay that cannot drop a destination in place re-creates
                // the post, so its id may change.
                $id = $providers->for($post)->replaceTargets($post->provider_post_id, $payload);
                if ($id !== $post->provider_post_id) {
                    $post->update(['provider_post_id' => $id]);
                }
            }

            DB::transaction(function () use ($post, $target, $why) {
                if ($post->provider_post_id !== null && $target->status === SocialPostTargetStatus::Pending) {
                    Credits::returnSend($post, [$target], $why);
                }
                $target->delete();
            });
        }

        $provider = $providers->for($socialAccount);
        $provider->disconnect($socialAccount->provider_account_id);
        $provider->deleteAccount($socialAccount->provider_account_id);

        $socialAccount->update(['status' => SocialAccountStatus::Disconnected]);
        ProductEvents::channelsCount($socialAccount->user);

        return response()->json(null, 204);
    }

    /**
     * Does this account's grant read DMs? Outstand's word when it reports
     * scopes; otherwise an Instagram account seen right after a sign-in
     * that asked for the messages scope is taken to have it (a DM or a
     * thread list later proves it; a 403 takes it back).
     *
     * @param  array<string, mixed>  $remote
     */
    private function recordMessagesScope(SocialAccount $row, array $remote, bool $askedForMessages): void
    {
        if (! $row->supportsMessages()) {
            return;
        }

        $scopes = $remote['scopes'] ?? null;

        if (is_array($scopes)) {
            $has = in_array(OutstandService::MESSAGES_SCOPE, $scopes, true);
            if ($has !== ($row->messages_scope_at !== null)) {
                $row->forceFill(['messages_scope_at' => $has ? now() : null])->save();
            }

            return;
        }

        if ($askedForMessages && $row->messages_scope_at === null) {
            $row->forceFill(['messages_scope_at' => now()])->save();
        }
    }

    /**
     * When the account was connected — not when we last looked. The
     * relay's own record of the grant wins (a reconnect is a new grant,
     * so a new date); without one, the date we first saw it stays until
     * it comes back from Disconnected. Re-stamping it on every sync made
     * Channels read "Connected" on the day of the last self-heal.
     *
     * @param  array<string, mixed>  $remote
     */
    private static function connectedAt(array $remote, ?SocialAccount $existing, SocialAccountStatus $status): CarbonImmutable
    {
        $reported = $remote['connected_at'] ?? null;
        if (is_string($reported) && $reported !== '') {
            try {
                return CarbonImmutable::parse($reported)->utc();
            } catch (Throwable) {
                // Unreadable: fall through to our own record.
            }
        }

        $reconnected = $existing !== null
            && $existing->status !== SocialAccountStatus::Connected
            && $status === SocialAccountStatus::Connected;

        return $existing?->connected_at !== null && ! $reconnected
            ? $existing->connected_at
            : CarbonImmutable::now();
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
    private function finishClaim(User $user, SocialProvider $provider): void
    {
        $tag = Cache::get("social-claim-by:{$user->id}");
        $claim = $tag ? Cache::get("social-claim:{$tag}") : null;

        if ($claim === null || $claim['claimant'] !== $user->id) {
            Cache::forget("social-claim-by:{$user->id}");

            return;
        }

        foreach ($provider->accountsFor($tag) as $account) {
            $id = (string) ($account['id'] ?? '');

            if ($id === '') {
                continue;
            }

            // The claimed one is read on its own: an empty token from a list
            // that leaves it out must never pass as "changed".
            $token = $id === $claim['account_id']
                ? (string) ($provider->account($id)['access_token'] ?? '')
                : '';
            $proven = $id !== $claim['account_id']
                || ($token !== '' && hash('sha256', $token) !== $claim['token']);

            $provider->assignAccount($id, $proven ? $user->ulid : $claim['owner']);

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
    private function parked(string $accountId, User $owner, SocialProvider $provider): bool
    {
        $tag = (string) ($provider->account($accountId)['external_id'] ?? '');
        $claim = str_starts_with($tag, self::CLAIM) ? Cache::get("social-claim:{$tag}") : null;

        if ($claim === null || $claim['owner'] !== $owner->ulid) {
            return false;
        }

        if ($claim['until'] <= now()->timestamp) {
            Cache::forget("social-claim:{$tag}");
            $provider->assignAccount($accountId, $owner->ulid);
        }

        return true;
    }
}
