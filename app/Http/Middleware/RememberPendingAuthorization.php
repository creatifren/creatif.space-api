<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the consent screen is shown, remember which authorize URL it is for
 * and who it was shown to, next to Passport's own authToken. "Not you? Use
 * another account" returns to exactly this URL — state, PKCE challenge and
 * redirect_uri as the client sent them — and never to one a form posted.
 */
class RememberPendingAuthorization
{
    public const KEY = 'oauth.pending';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Passport renders the consent view (200) only after putting a fresh
        // authToken in the session; anything else is a redirect or an error.
        $authToken = $request->session()->get('authToken');
        $user = $request->user('web');
        if ($response->getStatusCode() === 200 && is_string($authToken) && $user !== null) {
            $request->session()->put(self::KEY, [
                'url' => $request->fullUrl(),
                'user_id' => (string) $user->getAuthIdentifier(),
                'auth_token' => $authToken,
            ]);
        }

        return $response;
    }

    /**
     * The remembered authorize URL — only ever an /oauth/authorize URL on
     * this host — or null.
     */
    public static function url(Request $request): ?string
    {
        $url = ((array) $request->session()->get(self::KEY, []))['url'] ?? null;

        return is_string($url) && str_starts_with($url, url('/oauth/authorize').'?') ? $url : null;
    }
}
