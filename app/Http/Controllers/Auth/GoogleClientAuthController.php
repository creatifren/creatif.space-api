<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Support\SafePath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * The third OAuth pass: a client signing in to approve. Identity only —
 * no Drive scope, no dashboard behind it.
 *
 * Written by hand rather than through Socialite because this flow carries
 * the visitor back to the exact Space they were reading, which means the
 * return path has to survive the round trip to Google.
 */
class GoogleClientAuthController extends Controller
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Send the visitor to Google. `return` is where they land afterwards —
     * the Space they were looking at when they pressed Approve.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = str()->random(40);
        $request->session()->put('client_oauth_state', $state);
        $request->session()->put(
            'client_oauth_return',
            SafePath::of($request->query('return')),
        );

        return redirect(self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.approver_redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
        ]));
    }

    /**
     * Exchange the code, find or create the Client, start the session on
     * the `client` guard — never on `web`, which is the creator's.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontend = config('app.frontend_url');
        $return = SafePath::of($request->session()->pull('client_oauth_return'));

        if (
            $request->query('state') !== $request->session()->pull('client_oauth_state')
            || $request->query('code') === null
        ) {
            return redirect($frontend.$return.'?approve=error');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => (string) $request->query('code'),
            'redirect_uri' => config('services.google.approver_redirect'),
        ]);

        if ($response->failed()) {
            return redirect($frontend.$return.'?approve=error');
        }

        // Claims come straight from Google over TLS — decoding is enough,
        // the signature adds nothing here (same reasoning as Drive connect).
        $claims = json_decode(base64_decode(
            str_replace(['-', '_'], ['+', '/'], explode('.', (string) $response->json('id_token'))[1] ?? ''),
        ) ?: '{}', true);

        $googleId = (string) ($claims['sub'] ?? '');
        $email = (string) ($claims['email'] ?? '');

        if ($googleId === '' || $email === '') {
            return redirect($frontend.$return.'?approve=error');
        }

        $client = Client::query()->where('google_id', $googleId)->first()
            ?? Client::query()->where('email', $email)->first()
            ?? new Client;

        $client->fill([
            'google_id' => $googleId,
            'email' => $email,
            'name' => $claims['name'] ?? $client->name,
            'avatar_url' => $claims['picture'] ?? $client->avatar_url,
            // Same address as a creator account? Then the two are the same
            // person, and Insights can show what they approved elsewhere.
            'user_id' => User::query()->where('email', $email)->value('id'),
        ])->save();

        Auth::guard('client')->login($client, remember: true);
        $request->session()->regenerate();

        return redirect($frontend.$return.'?approve=ready');
    }

    /**
     * Sign the client out without touching the creator session that may be
     * running alongside it in the same browser.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('client')->logout();

        return response()->noContent();
    }
}
