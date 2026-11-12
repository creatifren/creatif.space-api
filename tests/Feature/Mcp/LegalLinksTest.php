<?php

use App\Models\User;

/**
 * Directory readiness: the consent screen links to the Terms and Privacy
 * Policy, and OAuth discovery names them (RFC 8414 op_policy_uri /
 * op_tos_uri, RFC 9728 resource_policy_uri / resource_tos_uri).
 */
beforeEach(function () {
    ensurePassportKeys();
    config(['app.url' => 'https://api.creatif.space', 'app.frontend_url' => 'https://app.creatif.space']);
});

it('names the privacy policy and terms in authorization server metadata', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('op_policy_uri', 'https://creatif.space/privacy')
        ->assertJsonPath('op_tos_uri', 'https://creatif.space/terms');
});

it('names them in protected resource metadata, at both paths', function (string $path) {
    $this->getJson($path)
        ->assertOk()
        ->assertJsonPath('resource_policy_uri', 'https://creatif.space/privacy')
        ->assertJsonPath('resource_tos_uri', 'https://creatif.space/terms');
})->with(['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']);

it('links to the Terms and the Privacy Policy on the consent screen', function () {
    $client = mcpClient();
    [, $challenge] = pkcePair();

    $this->actingAs(User::factory()->create(), 'web')
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'read',
            'state' => 's',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('href="https://creatif.space/terms"', false)
        ->assertSee('href="https://creatif.space/privacy"', false)
        ->assertSee('Privacy Policy');
});

it('follows config for the two pages', function () {
    config(['mcp.privacy_url' => 'https://example.test/p', 'mcp.terms_url' => 'https://example.test/t']);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertJsonPath('op_policy_uri', 'https://example.test/p')
        ->assertJsonPath('op_tos_uri', 'https://example.test/t');
});
