<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OutstandService;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The browser's way back from an Outstand sign-in, and the page picker
 * that Facebook, LinkedIn and Google Business end in. Outstand holds those
 * as a pending session — `?session=` on our return URL — until the pages
 * to connect are named. One page is connected straight away; several are
 * parked under the user for the connect page to offer. Every other network
 * (and an account Outstand already had) comes back without a usable
 * session: the return then syncs the tenant's accounts itself.
 */
class OutstandConnectController extends Controller
{
    /** Outstand keeps a pending session 30 minutes. */
    private const PENDING_MINUTES = 30;

    public function return(Request $request, OutstandService $outstand, SocialAccountController $accounts): RedirectResponse
    {
        $page = config('app.frontend_url').'/social/connect?';
        $tenant = OutstandService::unseal($request->string('state')->toString());
        $session = $request->string('session')->toString();
        $user = $tenant !== null ? User::query()->where('ulid', $tenant)->first() : null;

        if ($tenant === null || $user === null) {
            return redirect($page.http_build_query([
                'social' => 'connected', 'isSuccess' => 'false', 'error' => 'The sign-in expired. Try connecting again.',
            ]));
        }

        $log = [
            'tenant' => $tenant,
            'platform' => $request->string('network')->toString() ?: ($request->string('platform')->toString() ?: null),
            'had_session' => $session !== '',
            'pending_status' => 'none',
            'accounts_found' => null,
        ];

        // Facebook / LinkedIn / Google: a page picker, held as a pending session.
        if ($session !== '') {
            try {
                $pending = $outstand->pendingConnection($session);
                $log['pending_status'] = $pending === null ? '404' : (count($pending['pages']).' pages');
            } catch (Throwable $e) {
                $pending = null;
                $log['pending_status'] = 'error';
            }

            if ($pending !== null && $pending['pages'] !== []) {
                $log['platform'] ??= $pending['network'];
                Log::info('social.connect_return', $log);

                if (count($pending['pages']) === 1) {
                    $outstand->finalizeConnection($session, [$pending['pages'][0]['id']]);

                    return redirect($page.http_build_query(['social' => 'connected']));
                }

                Cache::put("outstand-pending:{$tenant}", $session, now()->addMinutes(self::PENDING_MINUTES));

                return redirect($page.http_build_query(['social' => 'pending', 'network' => $pending['network']]));
            }
        }

        // Everything else is connected by Outstand directly (Instagram,
        // Threads, X, TikTok …), or was already there: no pending session,
        // or one that 404s. What the tenant holds at Outstand is the answer.
        $outstandError = $request->string('error')->toString();
        if (self::alreadyElsewhere($outstandError)) {
            Log::info('social.connect_return', $log + ['outcome' => 'already_connected_elsewhere']);

            return $this->elsewhere($page);
        }

        try {
            $remote = $outstand->accountsFor($tenant);
        } catch (Throwable $e) {
            Log::warning('social.connect_return', $log + ['outcome' => 'list_failed', 'error' => $e->getMessage()]);

            // Not lost: Channels re-syncs when it loads.
            return redirect($page.http_build_query([
                'social' => 'connected', 'isSuccess' => 'false',
                'error' => 'We couldn’t confirm the connection just now. Refresh Channels in a minute.',
            ]));
        }
        $log['accounts_found'] = count($remote);

        // Every account Outstand lists for this tenant is held, connected,
        // by another of our users: the same account under two workspaces.
        $ids = array_values(array_filter(array_map(fn ($a) => (string) ($a['id'] ?? ''), $remote)));
        $heldElsewhere = $ids === [] ? 0 : SocialAccount::query()
            ->where('provider', $outstand->name())
            ->whereIn('provider_account_id', $ids)
            ->where('user_id', '!=', $user->id)
            ->where('status', SocialAccountStatus::Connected)
            ->distinct()
            ->count('provider_account_id');
        if ($ids !== [] && $heldElsewhere === count(array_unique($ids))) {
            Log::info('social.connect_return', $log + ['outcome' => 'already_connected_elsewhere']);

            return $this->elsewhere($page);
        }

        $accounts->syncFor($user, $outstand, $remote);
        $connected = $user->socialAccounts()
            ->where('provider', $outstand->name())
            ->where('status', SocialAccountStatus::Connected)
            ->count();

        Log::info('social.connect_return', $log + ['outcome' => $connected > 0 ? 'synced' : 'nothing']);

        if ($connected === 0) {
            return redirect($page.http_build_query([
                'social' => 'connected', 'isSuccess' => 'false',
                'error' => $outstandError !== ''
                    ? mb_substr($outstandError, 0, 200)
                    : 'The sign-in finished, but no account came back from it. Try connecting again.',
            ]));
        }

        return redirect($page.http_build_query(['social' => 'connected', 'count' => $connected]));
    }

    private function elsewhere(string $page): RedirectResponse
    {
        return redirect($page.http_build_query([
            'social' => 'connected', 'isSuccess' => 'false', 'code' => 'already_connected_elsewhere',
            'error' => 'This account is already connected to another Creatif Space account',
        ]));
    }

    /** Outstand's own error on the return, when it names another tenant. */
    private static function alreadyElsewhere(string $error): bool
    {
        $e = strtolower($error);

        return $e !== '' && str_contains($e, 'already')
            && (str_contains($e, 'tenant') || str_contains($e, 'another') || str_contains($e, 'linked') || str_contains($e, 'connected'));
    }

    /** The pages waiting for this user to choose from, if any. */
    public function pending(Request $request, OutstandService $outstand): JsonResponse
    {
        $session = Cache::get("outstand-pending:{$request->user()->ulid}");
        $pending = is_string($session) ? $outstand->pendingConnection($session) : null;

        return response()->json(['data' => $pending]);
    }

    /**
     * Connect the chosen pages, within the plan's room. The frontend syncs
     * afterwards, as after any connect.
     */
    public function finalize(Request $request, OutstandService $outstand): JsonResponse
    {
        $validated = $request->validate([
            'page_ids' => ['required', 'array', 'min:1', 'max:20'],
            'page_ids.*' => ['string', 'max:255'],
        ]);
        $user = $request->user();
        $session = Cache::get("outstand-pending:{$user->ulid}");
        $pending = is_string($session) ? $outstand->pendingConnection($session) : null;

        if (! is_string($session) || $pending === null) {
            throw ValidationException::withMessages(['page_ids' => 'That sign-in expired. Connect again.']);
        }

        $offered = array_column($pending['pages'], 'id');
        $ids = array_values(array_unique($validated['page_ids']));

        if (array_diff($ids, $offered) !== []) {
            throw ValidationException::withMessages(['page_ids' => 'Choose from the pages that sign-in offered.']);
        }

        // The same count PlanQuota::canConnectSocialAccount keeps, for N at once.
        $owner = Workspace::owner($user);
        $limit = $owner->plan()->quota('social_accounts');
        $used = $owner->socialAccounts()->usable()->count();
        if ($limit !== null && $used + count($ids) > $limit) {
            throw ValidationException::withMessages([
                'page_ids' => "Your plan connects up to {$limit} social accounts. Choose fewer, or upgrade to add more.",
            ]);
        }

        $outstand->finalizeConnection($session, $ids);
        Cache::forget("outstand-pending:{$user->ulid}");

        return response()->json(['data' => ['connected' => count($ids)]]);
    }
}
