<?php

namespace App\Http\Middleware;

use App\OAuth\AuthorizeResume;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport keeps one pending authorization per session (authToken +
 * authRequest): opening the consent screen again — a retry, a second tab,
 * Back — replaced it, and Allow on the earlier screen answered a bare 403
 * "The provided auth token … is different from the session auth token".
 *
 * Here every consent screen shown is kept under its own auth token (up to
 * MAX, for TTL_SECONDS). Allow / Deny restore the request their form was
 * rendered for, just before Passport reads the session, so each open
 * screen works — once: a token is taken out when it is used. An unknown,
 * used or expired token never reaches Passport's 403: the person sees the
 * latest pending consent screen again, or a page saying the request
 * expired.
 */
class PendingAuthorizations
{
    public const KEY = 'oauth.requests';

    public const MAX = 10;

    public const TTL_SECONDS = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            $response = $next($request);
            $this->remember($request, $response);

            return $response;
        }

        $token = $request->input('auth_token');
        $entries = $this->entries($request);

        if (is_string($token) && isset($entries[$token])) {
            $entry = $entries[$token];
            unset($entries[$token]);
            $request->session()->put(self::KEY, $entries);
            $request->session()->put('authToken', $token);
            $request->session()->put('authRequest', $entry['request']);
            $pending = (array) $request->session()->get(RememberPendingAuthorization::KEY, []);
            $request->session()->put(RememberPendingAuthorization::KEY, ['url' => $entry['url'], 'auth_token' => $token] + $pending);

            return $next($request);
        }

        // Stock Passport path: the one token the session holds.
        if (is_string($token) && $token !== '' && $request->session()->get('authToken') === $token) {
            return $next($request);
        }

        // Used, unknown or expired: never a bare 403. Show the request this
        // form was for again (its signed copy survives a session that was
        // reset by signing out or switching account), else the latest one.
        $request->session()->forget(['authToken', 'authRequest']);
        $resume = AuthorizeResume::url($request->input('resume'));
        if ($resume !== null) {
            return redirect()->to($resume);
        }
        $latest = $entries === [] ? null : $entries[array_key_last($entries)];
        if ($latest !== null) {
            return redirect()->to($latest['url']);
        }

        return response()->view('oauth.expired', [], 400);
    }

    private function remember(Request $request, Response $response): void
    {
        $token = $request->session()->get('authToken');
        $authRequest = $request->session()->get('authRequest');
        if ($response->getStatusCode() !== 200 || ! is_string($token) || ! is_string($authRequest)) {
            return;
        }

        $entries = $this->entries($request);
        $entries[$token] = ['request' => $authRequest, 'url' => $request->fullUrl(), 'at' => now()->getTimestamp()];
        $request->session()->put(self::KEY, array_slice($entries, -self::MAX, null, true));
    }

    /**
     * The pending requests still in time, oldest first.
     *
     * @return array<string, array{request: string, url: string, at: int}>
     */
    private function entries(Request $request): array
    {
        $entries = $request->session()->get(self::KEY, []);
        if (! is_array($entries)) {
            return [];
        }

        $since = now()->getTimestamp() - self::TTL_SECONDS;
        $kept = [];
        foreach ($entries as $token => $e) {
            if (is_string($token) && is_array($e) && is_string($e['request'] ?? null) && is_string($e['url'] ?? null)
                && is_int($e['at'] ?? null) && $e['at'] >= $since) {
                $kept[$token] = ['request' => $e['request'], 'url' => $e['url'], 'at' => $e['at']];
            }
        }

        return $kept;
    }
}
