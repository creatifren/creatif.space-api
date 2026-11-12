<?php

namespace App\Http\Middleware;

use App\OAuth\ClientMetadataDocuments;
use App\OAuth\ClientMetadataException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On /oauth/authorize, /oauth/token and /oauth/revoke: a client_id that is
 * a metadata document URL is swapped for the Passport client it resolves
 * to, so Passport handles the rest as for any public client. Any other
 * client_id (DCR, first-party) passes through untouched.
 */
class ResolveClientMetadataDocument
{
    public function __construct(private readonly ClientMetadataDocuments $documents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $request->input('client_id');
        if (! ClientMetadataDocuments::isUrl($clientId)) {
            return $next($request);
        }

        try {
            $client = $this->documents->resolve($clientId);
        } catch (ClientMetadataException $e) {
            return response()->json(
                ['error' => 'invalid_client', 'error_description' => $e->getMessage()],
                $request->isMethod('GET') ? 400 : 401,
                ['Cache-Control' => 'no-store'],
            );
        }

        $id = (string) $client->getKey();
        foreach ([$request->query, $request->request] as $bag) {
            if ($bag->has('client_id')) {
                $bag->set('client_id', $id);
            }
        }
        if ($request->isJson() && $request->json()->has('client_id')) {
            $request->json()->set('client_id', $id);
        }

        return $next($request);
    }
}
