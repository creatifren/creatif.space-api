<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport fixes the user of an authorization request when it shows the
 * consent screen, and Allow later issues the code to that user — whoever is
 * signed in by then. A session that changed hands in between (signed in as
 * someone else in another tab) must not hand the earlier person a token:
 * the pending request is dropped and the person who is signed in now is
 * shown the consent screen for themselves.
 */
class BindAuthRequestToSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $serialized = $request->session()->get('authRequest');
        $current = $request->user('web');

        if (is_string($serialized) && $current !== null) {
            $authRequest = unserialize($serialized, ['allowed_classes' => [
                AuthorizationRequest::class, Client::class, Scope::class, User::class,
            ]]);

            $owner = $authRequest instanceof AuthorizationRequest ? $authRequest->getUser()?->getIdentifier() : null;

            if ((string) $owner !== (string) $current->getAuthIdentifier()) {
                $url = RememberPendingAuthorization::url($request);
                $request->session()->forget(['authToken', 'authRequest', RememberPendingAuthorization::KEY]);

                return $url !== null
                    ? redirect()->to($url)
                    : abort(403, 'This sign-in request belongs to another account. Start connecting again.');
            }
        }

        return $next($request);
    }
}
