<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OutstandService;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The browser's way back from an Outstand sign-in, and the page picker
 * that Facebook, LinkedIn and Google Business end in. Outstand holds those
 * as a pending session — `?session=` on our return URL — until the pages
 * to connect are named. One page is connected straight away; several are
 * parked under the user for the connect page to offer.
 */
class OutstandConnectController extends Controller
{
    /** Outstand keeps a pending session 30 minutes. */
    private const PENDING_MINUTES = 30;

    public function return(Request $request, OutstandService $outstand): RedirectResponse
    {
        $page = config('app.frontend_url').'/social/connect?';
        $tenant = OutstandService::unseal($request->string('state')->toString());
        $session = $request->string('session')->toString();

        if ($tenant === null) {
            return redirect($page.http_build_query([
                'social' => 'connected', 'isSuccess' => 'false', 'error' => 'The sign-in expired. Try connecting again.',
            ]));
        }

        if ($session === '') {
            // Outstand's own success/error params ride along, as Post for Me's did.
            return redirect($page.http_build_query(['social' => 'connected'] + $request->only(['success', 'error'])));
        }

        $pending = $outstand->pendingConnection($session);

        if ($pending === null || $pending['pages'] === []) {
            return redirect($page.http_build_query([
                'social' => 'connected', 'isSuccess' => 'false', 'error' => 'Nothing to connect from that sign-in.',
            ]));
        }

        if (count($pending['pages']) === 1) {
            $outstand->finalizeConnection($session, [$pending['pages'][0]['id']]);

            return redirect($page.http_build_query(['social' => 'connected']));
        }

        Cache::put("outstand-pending:{$tenant}", $session, now()->addMinutes(self::PENDING_MINUTES));

        return redirect($page.http_build_query(['social' => 'pending', 'network' => $pending['network']]));
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
