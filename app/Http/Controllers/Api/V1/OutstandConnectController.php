<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
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

    /**
     * Every way back from a connect lands on the app's connect screen,
     * never on a JSON page or a 500. Alongside the older params the app
     * reads (`social`, `isSuccess`, `error`, `code`), each answer carries
     * `connect` (success|pending|failed), `network` and, on failure,
     * `reason` (one of FAILURE_REASONS).
     */
    public function return(Request $request, OutstandService $outstand, SocialAccountController $accounts): RedirectResponse
    {
        $state = $request->string('state')->toString();

        // A refresh, or the back button, re-opening this URL: the state was
        // spent, so answer as the first time did.
        $again = OutstandService::spent($state);
        if ($again !== null) {
            return redirect($again);
        }

        $sealed = OutstandService::sealed($state);
        $network = self::network($sealed['network'] ?? null, $request);

        if ($sealed === null) {
            // Forged, mangled, or past our state's life. A well-formed one we
            // no longer hold is almost always an old tab: say "expired".
            $reason = OutstandService::wellFormed($state) ? 'expired' : 'invalid_state';
            Log::info('social.connect_return', ['tenant' => null, 'platform' => $network, 'outcome' => $reason]);

            return redirect(self::failed($network, $reason));
        }

        $tenant = $sealed['tenant'];
        $user = User::query()->where('ulid', $tenant)->first();
        if ($user === null) {
            return redirect(self::failed($network, 'invalid_state'));
        }

        try {
            $target = $this->settle($request, $outstand, $accounts, $user, $tenant, $network);
        } catch (Throwable $e) {
            Log::warning('social.connect_return', ['tenant' => $tenant, 'platform' => $network, 'outcome' => 'exception', 'error' => $e->getMessage()]);
            $target = self::failed($network, 'error');
        }

        $parts = [];
        parse_str((string) parse_url($target, PHP_URL_QUERY), $parts);
        OutstandService::finishAttempt(
            $tenant,
            is_string($parts['connect'] ?? null) ? $parts['connect'] : 'failed',
            is_string($parts['reason'] ?? null) ? $parts['reason'] : null,
            $network,
        );
        OutstandService::spend($state, $target);

        return redirect($target);
    }

    /**
     * The reasons a connect can fail, as the app reads them in `reason`.
     *
     * @var list<string>
     */
    public const FAILURE_REASONS = [
        'expired',                     // our state or Outstand's 30-minute session ran out (or an old tab)
        'cancelled',                   // the user backed out on the network's screen
        'denied',                      // the user (or the network) refused the permissions
        'error',                       // the network or Outstand failed; nothing connected
        'invalid_state',               // a forged or mangled return
        'no_account',                  // the sign-in finished but brought no account back
        'already_connected_elsewhere', // that account belongs to another Creatif Space workspace
        'unconfirmed',                 // we could not check with Outstand; Channels re-syncs later
    ];

    /** What each reason tells the user (the older `error` param). */
    private const MESSAGES = [
        'expired' => 'The sign-in expired. Try connecting again.',
        'cancelled' => 'The sign-in was cancelled. Try connecting again when you are ready.',
        'denied' => 'The permissions were not granted, so nothing was connected. Try again and allow access.',
        'error' => 'The network could not finish the sign-in. Try connecting again.',
        'invalid_state' => 'That sign-in link is not valid any more. Try connecting again.',
        'no_account' => 'The sign-in finished, but no account came back from it. Try connecting again.',
        'already_connected_elsewhere' => 'This account is already connected to another Creatif Space account',
        'unconfirmed' => 'We couldn’t confirm the connection just now. Refresh Channels in a minute.',
    ];

    /** The app's connect screen, for whatever the return decided. */
    private function settle(Request $request, OutstandService $outstand, SocialAccountController $accounts, User $user, string $tenant, ?string $network): string
    {
        $session = $request->string('session')->toString();

        $log = [
            'tenant' => $tenant,
            'platform' => $network,
            'had_session' => $session !== '',
            'pending_status' => 'none',
            'accounts_found' => null,
        ];

        // The network or Outstand said no. Checked first, so a refusal never
        // syncs and reads as a success because the tenant had other accounts.
        $outstandError = self::providerError($request);
        if ($outstandError !== null && ! self::alreadyElsewhere($outstandError)) {
            $reason = self::reasonFor($outstandError);
            Log::info('social.connect_return', $log + ['outcome' => $reason, 'error' => mb_substr($outstandError, 0, 200)]);

            return self::failed($network, $reason);
        }
        if ($outstandError !== null) {
            Log::info('social.connect_return', $log + ['outcome' => 'already_connected_elsewhere']);

            return self::failed($network, 'already_connected_elsewhere');
        }

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
                $network ??= $pending['network'];
                $log['platform'] = $network;
                Log::info('social.connect_return', $log);

                if (count($pending['pages']) === 1) {
                    $outstand->finalizeConnection($session, [$pending['pages'][0]['id']]);

                    return self::page(['social' => 'connected', 'connect' => 'success', 'network' => $network]);
                }

                Cache::put("outstand-pending:{$tenant}", $session, now()->addMinutes(self::PENDING_MINUTES));

                return self::page(['social' => 'pending', 'network' => $pending['network'], 'connect' => 'pending']);
            }
        }

        // Everything else is connected by Outstand directly (Instagram,
        // Threads, X, TikTok …), or was already there: no pending session,
        // or one that 404s. What the tenant holds at Outstand is the answer.
        try {
            $remote = $outstand->accountsFor($tenant);
        } catch (Throwable $e) {
            Log::warning('social.connect_return', $log + ['outcome' => 'list_failed', 'error' => $e->getMessage()]);

            // Not lost: Channels re-syncs when it loads.
            return self::failed($network, 'unconfirmed');
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

            return self::failed($network, 'already_connected_elsewhere');
        }

        $accounts->syncFor($user, $outstand, $remote);
        $connected = $user->socialAccounts()
            ->where('provider', $outstand->name())
            ->where('status', SocialAccountStatus::Connected)
            ->count();

        Log::info('social.connect_return', $log + ['outcome' => $connected > 0 ? 'synced' : 'nothing']);

        if ($connected === 0) {
            return self::failed($network, 'no_account');
        }

        return self::page(['social' => 'connected', 'count' => $connected, 'connect' => 'success', 'network' => $network]);
    }

    /**
     * The app's connect screen with these params (nulls dropped).
     *
     * @param  array<string, string|int|null>  $params
     */
    private static function page(array $params): string
    {
        return config('app.frontend_url').'/social/connect?'.http_build_query(array_filter($params, fn ($v) => $v !== null));
    }

    /** A failed connect, in both the older params and connect/network/reason. */
    private static function failed(?string $network, string $reason): string
    {
        return self::page([
            'social' => 'connected',
            'isSuccess' => 'false',
            'code' => $reason === 'already_connected_elsewhere' ? $reason : null,
            'error' => self::MESSAGES[$reason] ?? self::MESSAGES['error'],
            'connect' => 'failed',
            'network' => $network,
            'reason' => $reason,
        ]);
    }

    /** The network being connected: sealed with the state, else as Outstand names it. */
    private static function network(?string $sealed, Request $request): ?string
    {
        foreach ([$sealed, $request->query('network'), $request->query('platform')] as $candidate) {
            if (is_string($candidate) && SocialPlatform::tryFrom($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Outstand's (or the network's) error on the return, if any: `error`,
     * `error_reason`, `error_code`, `error_description`, or
     * `isSuccess=false` / `success=false` with no text.
     */
    private static function providerError(Request $request): ?string
    {
        $parts = [];
        foreach (['error', 'error_reason', 'error_code', 'error_description'] as $key) {
            $value = $request->query($key);
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }
        if ($parts !== []) {
            return implode(' ', $parts);
        }

        foreach (['isSuccess', 'success'] as $key) {
            $flag = $request->query($key);
            if (is_string($flag) && in_array(strtolower($flag), ['false', '0'], true)) {
                return 'unsuccessful';
            }
        }

        return null;
    }

    /** A provider's error text, as one of FAILURE_REASONS. */
    public static function reasonFor(string $error): string
    {
        $e = strtolower($error);

        return match (true) {
            str_contains($e, 'cancel') => 'cancelled',
            str_contains($e, 'expired') || str_contains($e, 'timeout') || str_contains($e, 'timed out')
                || str_contains($e, 'invalid state') || str_contains($e, 'invalid_state') || str_contains($e, 'session') => 'expired',
            str_contains($e, 'denied') || str_contains($e, 'not authorized') || str_contains($e, 'unauthorized')
                || str_contains($e, 'declined') || str_contains($e, 'permission') => 'denied',
            default => 'error',
        };
    }

    /** Outstand's own error on the return, when it names another tenant. */
    private static function alreadyElsewhere(string $error): bool
    {
        $e = strtolower($error);

        return $e !== '' && str_contains($e, 'already')
            && (str_contains($e, 'tenant') || str_contains($e, 'another') || str_contains($e, 'linked') || str_contains($e, 'connected'));
    }

    /**
     * The user's last connect, for the app to read when the user comes back
     * to it — above all from an Outstand page we could not redirect away
     * from (its 30-minute session ran out). `status` is pending | success |
     * failed; a pending one past `expires_at` already reads failed/expired.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => OutstandService::attempt($request->user()->ulid)]);
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
