<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * navigator.sendBeacon can only send a CORS-safelisted body without a
 * preflight, so the approval page's last-chance decision ("approve, then
 * close the tab") arrives as text/plain holding JSON. Read it as JSON.
 * Anything that is not a JSON object stays as it came, and validation
 * answers it as usual.
 */
class AcceptBeaconJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'text/plain')) {
            $data = json_decode((string) $request->getContent(), true);
            if (is_array($data) && ! array_is_list($data)) {
                $request->headers->set('Content-Type', 'application/json');
                $request->setJson(new InputBag($data));
            }
        }

        return $next($request);
    }
}
