<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RememberPendingAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Not you? Use another account" on the MCP consent screen.
 *
 * Signs out of Creatif Space only — this session, never the person's Google
 * account — and goes back to the same authorize URL the client sent. As a
 * guest that URL sends them to the frontend's login and, once signed in as
 * the other account, back to the consent screen for it, so the client's
 * original request (state, PKCE challenge, redirect_uri) completes for the
 * account that approves it.
 *
 * The session is invalidated, so the pending request Passport kept for the
 * previous account is gone with it: nothing can be approved for them after
 * the switch. CSRF-protected like every web POST.
 */
class SwitchAccountController extends Controller
{
    /** Read by GoogleAuthController::redirect: show Google's account chooser once. */
    public const SELECT_ACCOUNT = 'auth.select_account';

    public function __invoke(Request $request): RedirectResponse
    {
        $url = RememberPendingAuthorization::url($request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($url === null) {
            return redirect(config('app.frontend_url').'/login');
        }

        // Google would otherwise sign the same Google account straight back in.
        $request->session()->put(self::SELECT_ACCOUNT, true);

        return redirect()->to($url);
    }
}
