<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\GoogleIdentity;
use App\Actions\Auth\ResolveGoogleUser;
use App\Exceptions\GoogleLoginRefused;
use App\Exceptions\InvalidGoogleIdToken;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OAuth\SwitchAccountController;
use App\Support\GoogleIdToken;
use App\Support\ProductEvents;
use App\Support\SafePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to Google's OAuth consent screen.
     *
     * Login-only: no Drive scope. Files are uploaded straight to R2.
     */
    public function redirect(Request $request): SymfonyRedirectResponse
    {
        // The frontend appends ?ref= from its 90-day cookie. It has to
        // travel through the session because the cookie belongs to the
        // Next.js origin and Laravel never sees it.
        $code = $request->query('ref');

        if (is_string($code) && $code !== '') {
            $request->session()->put('referral_code', substr($code, 0, 30));
        }

        // Where they were headed before the guard sent them to /login. Travels
        // through the session for the same reason as the referral code, and is
        // checked here as well as on the way back — the value came from a URL
        // a stranger could have written.
        // Campaign attribution for the signup event, the same trip as ?ref=.
        $utm = ProductEvents::utmFrom($request);
        if ($utm !== []) {
            $request->session()->put('signup_utm', $utm);
        }
        // Which page or button they came from (analytics only, never a referral).
        $entry = ProductEvents::entryFrom($request);
        if ($entry !== null) {
            $request->session()->put('signup_entry', $entry);
        }

        $next = $request->query('next');

        if (is_string($next) && $next !== '') {
            $request->session()->put('next_path', SafePath::of($next));
        }

        $driver = Socialite::driver('google');

        // Back from "Not you? Use another account" on the MCP consent screen:
        // let them pick the other Google account instead of being signed
        // straight back in as the one they just left. Their Google session
        // itself is never touched.
        if ($request->session()->pull(SwitchAccountController::SELECT_ACCOUNT, false) === true
            && $driver instanceof AbstractProvider) {
            $driver->with(['prompt' => 'select_account']);
        }

        return $driver->redirect();
    }

    /**
     * Handle the OAuth callback: find or create the creator, start the
     * session (Sanctum SPA cookie), and hand off to the Next.js frontend.
     */
    public function callback(Request $request, ResolveGoogleUser $resolve): RedirectResponse
    {
        /* Cancel on Google's consent screen comes back as ?error=access_denied
           with no code; a stale or replayed callback fails the state check.
           Neither is a server fault: send them back to the login page. */
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->cancelled();
        }

        try {
            /** @var GoogleUser $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->cancelled();
        }

        try {
            $user = $resolve->handle(new GoogleIdentity(
                id: (string) $googleUser->getId(),
                email: (string) $googleUser->getEmail(),
                name: $googleUser->getName() ?? $googleUser->getNickname(),
                avatar: $googleUser->getAvatar(),
            ), $request);
        } catch (GoogleLoginRefused $e) {
            return redirect(config('app.frontend_url').'/login?error='.$e->reason);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        /* Where to land: the page the guard interrupted, checked a second
           time because a session value is only as trustworthy as what was
           put in it — otherwise Home. */
        return redirect(self::landing($request->session()->pull('next_path')));
    }

    /**
     * Native Google Sign-In for the Android app (Capacitor shell).
     *
     * Google refuses OAuth inside a WebView, so the shell signs in with
     * Android Credential Manager (serverClientId = our WEB client id,
     * services.google.client_id) and the page inside the WebView posts the
     * ID token here — same origin rules, cookies and CSRF as the web app:
     *
     *     GET  /sanctum/csrf-cookie
     *     POST /auth/google/native   X-XSRF-TOKEN, credentials: include
     *          {"id_token": "<JWT>", "next": "/spaces"}   (next optional)
     *
     * The token is verified offline against Google's keys (GoogleIdToken),
     * then the account is found, created or linked exactly as on the web
     * (ResolveGoogleUser) and the session starts.
     *
     *   200 {"redirect": "<FRONTEND_URL><next or /home>"}
     *   422 {"reason": "invalid_token"}      bad signature/aud/iss/exp, unverified email — one message, reason logged
     *   403 {"reason": "suspended"}
     *   403 {"reason": "google_not_linked"}  staff address matched by email only
     *   429 throttled (10/min per IP)
     *   503 {"reason": "google_unavailable"} Google's key set could not be fetched
     *
     * The token itself is never logged.
     */
    public function native(Request $request, GoogleIdToken $tokens, ResolveGoogleUser $resolve): JsonResponse
    {
        // A missing or oversized token is just another invalid one: JSON
        // either way, whatever Accept header the WebView sent.
        $token = $request->input('id_token');
        $next = $request->input('next');

        try {
            if (! is_string($token) || $token === '' || strlen($token) > 8192) {
                throw new InvalidGoogleIdToken('missing or oversized id_token');
            }
            $google = $tokens->verify($token);
        } catch (InvalidGoogleIdToken $e) {
            Log::info('auth.google_native_invalid_token', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['message' => 'Google sign-in could not be verified. Try again.', 'reason' => 'invalid_token'], 422);
        } catch (RuntimeException $e) {
            Log::warning('auth.google_native_jwks_unavailable', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Google sign-in is unavailable right now. Try again in a minute.', 'reason' => 'google_unavailable'], 503);
        }

        try {
            $user = $resolve->handle($google, $request, platform: 'android_app');
        } catch (GoogleLoginRefused $e) {
            return response()->json([
                'message' => $e->reason === 'suspended'
                    ? 'This account is suspended.'
                    : 'This account cannot sign in with Google until its Google account is linked.',
                'reason' => $e->reason,
            ], 403);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return response()->json(['redirect' => self::landing(is_string($next) ? $next : null)]);
    }

    /** The frontend page to land on: $next if it is one of ours, Home otherwise. */
    private static function landing(mixed $next): string
    {
        $wanted = SafePath::of($next);

        return config('app.frontend_url').($wanted !== '/' ? $wanted : '/home');
    }

    private function cancelled(): RedirectResponse
    {
        return redirect(config('app.frontend_url').'/login?error=google_cancelled');
    }
}
