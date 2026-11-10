<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Mcp\OAuthRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;

/**
 * RFC 7009 token revocation. The client authenticates the same way it
 * does at the token endpoint: public clients by client_id alone,
 * confidential ones with their secret (form or HTTP Basic). 200 whether
 * or not the token was known — telling would leak which tokens exist.
 */
class RevocationController extends Controller
{
    public function __invoke(Request $request, OAuthRevoker $revoker): JsonResponse
    {
        [$clientId, $secret] = $this->credentials($request);

        $client = is_string($clientId) && $clientId !== '' ? Client::query()->find($clientId) : null;

        $authenticated = $client !== null && ! $client->revoked && (
            ! $client->confidential() || (is_string($secret) && Hash::check($secret, (string) $client->getAttribute('secret')))
        );

        if (! $authenticated) {
            return response()->json(['error' => 'invalid_client'], 401, ['Cache-Control' => 'no-store']);
        }

        $token = $request->input('token');
        if (! is_string($token) || $token === '') {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'The token parameter is required.'], 400);
        }

        $hint = $request->input('token_type_hint');
        $revoker->revokeToken($token, (string) $client->getKey(), is_string($hint) ? $hint : null);

        return response()->json([], 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array{0: mixed, 1: mixed} */
    private function credentials(Request $request): array
    {
        if ($request->getUser() !== null) {
            return [urldecode((string) $request->getUser()), urldecode((string) $request->getPassword())];
        }

        return [$request->input('client_id'), $request->input('client_secret')];
    }
}
