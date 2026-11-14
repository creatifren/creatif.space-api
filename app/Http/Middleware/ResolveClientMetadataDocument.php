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
        if ($clientId === null && $request->isMethod('POST') && ($assertedId = self::clientIdFromAssertion($request)) !== null) {
            $request->request->set('client_id', $assertedId);
            $clientId = $assertedId;
        }
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

    /**
     * A client that prefers private_key_jwt (ChatGPT) may still send a
     * client_assertion, and RFC 7523 lets it leave client_id out then. We
     * treat metadata-document clients as public clients: the assertion is
     * NOT verified and grants nothing (PKCE's code_verifier is the proof),
     * so it is otherwise ignored. Only to find out who the client is when
     * client_id is missing, its "sub" (= the document URL) is read.
     */
    private static function clientIdFromAssertion(Request $request): ?string
    {
        $type = $request->input('client_assertion_type');
        $assertion = $request->input('client_assertion');
        if ($type !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' || ! is_string($assertion) || strlen($assertion) > 8192) {
            return null;
        }
        $parts = explode('.', $assertion);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(str_pad(strtr($parts[1], '-_', '+/'), (int) ceil(strlen($parts[1]) / 4) * 4, '='), true), true);
        $sub = is_array($payload) ? ($payload['sub'] ?? null) : null;

        return ClientMetadataDocuments::isUrl($sub) ? $sub : null;
    }
}
