<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\OAuth\RedirectUris;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\ClientRepository;

/**
 * RFC 7591 Dynamic Client Registration — how Claude, ChatGPT and other MCP
 * clients get a client_id without anyone filling in a form.
 *
 * Open registration (as the MCP spec expects), so what a client can ask
 * for is narrow: authorization_code + refresh_token, response_type code,
 * and the scopes we offer. A registration grants nothing by itself — every
 * user still sees the consent screen, which names the client.
 */
class ClientRegistrationController extends Controller
{
    public function __invoke(Request $request, ClientRepository $clients): JsonResponse
    {
        if (! config('mcp.registration_enabled')) {
            return $this->error('invalid_client_metadata', 'Client registration is closed right now.', 403);
        }

        $scopes = array_keys((array) config('mcp.scopes'));

        $validator = Validator::make($request->all(), [
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:2000'],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'client_uri' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'logo_uri' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'token_endpoint_auth_method' => ['sometimes', 'string', 'in:none,client_secret_post,client_secret_basic'],
            'grant_types' => ['sometimes', 'array'],
            'grant_types.*' => ['string', 'in:authorization_code,refresh_token'],
            'response_types' => ['sometimes', 'array'],
            'response_types.*' => ['string', 'in:code'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        if ($validator->fails()) {
            return $this->error('invalid_client_metadata', $validator->errors()->first());
        }

        $data = $validator->validated();

        foreach ($data['redirect_uris'] as $uri) {
            if (! RedirectUris::acceptable($uri)) {
                return $this->error('invalid_redirect_uri', "Redirect URI not allowed: {$uri}. Use https, a loopback http URL, or an app scheme.");
            }
        }

        $requested = array_filter(explode(' ', (string) ($data['scope'] ?? '')));
        if (array_diff($requested, $scopes) !== []) {
            return $this->error('invalid_client_metadata', 'Unknown scope. Supported: '.implode(' ', $scopes).'.');
        }

        $method = $data['token_endpoint_auth_method'] ?? 'none';
        $confidential = $method !== 'none';

        // Control characters out: the name is shown on the consent screen.
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($data['client_name'] ?? '')) ?? '');
        $name = $name === '' ? 'MCP client' : mb_substr($name, 0, 120);

        $client = $clients->createAuthorizationCodeGrantClient(
            $name,
            array_values(array_unique($data['redirect_uris'])),
            $confidential,
        );

        Log::info('oauth.client_registered', [
            'client_id' => $client->getKey(),
            'name' => $name,
            'confidential' => $confidential,
            'ip' => $request->ip(),
        ]);

        return response()->json(array_filter([
            'client_id' => (string) $client->getKey(),
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            // Only now, in plain text: Passport stores a hash.
            'client_secret' => $confidential ? $client->plainSecret : null,
            'client_secret_expires_at' => $confidential ? 0 : null,
            'client_name' => $name,
            'redirect_uris' => $client->getAttribute('redirect_uris'),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
            'scope' => implode(' ', $requested === [] ? $scopes : $requested),
        ], fn ($v) => $v !== null), 201, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }

    private function error(string $code, string $description, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], $status, ['Cache-Control' => 'no-store']);
    }
}
