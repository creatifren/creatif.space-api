<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with one bucket per route instead of one per visitor.
 *
 * Laravel's unnamed throttle keys on the IP (or user id) alone, so every
 * route shares a single counter: typing a handle on /onboarding hits
 * /handles/availability enough to make /auth/register answer 429 before
 * anyone has signed up. Adding the method and URI pattern keeps each limit
 * about its own route.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        return sha1($request->method().' '.$request->route()?->uri().'|'.parent::resolveRequestSignature($request));
    }
}
