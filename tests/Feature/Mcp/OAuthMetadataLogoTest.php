<?php

/*
 * Claude showed the old orange logo for the connector: neither metadata
 * document named a logo. Both now point at the rocket mark the MCP server
 * serves on its own origin (mcp.creatif.space/icon.png).
 */

it('names the rocket mark as logo_uri in the authorization server metadata', function () {
    config(['mcp.resource_url' => 'https://mcp.creatif.space/mcp']);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('logo_uri', 'https://mcp.creatif.space/icon.png');
});

it('names the same logo_uri next to resource_name in the protected resource metadata', function (string $path) {
    config(['mcp.resource_url' => 'https://mcp.creatif.space/mcp']);

    $this->getJson($path)
        ->assertOk()
        ->assertJsonPath('resource_name', 'Creatif Space')
        ->assertJsonPath('logo_uri', 'https://mcp.creatif.space/icon.png');
})->with(['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']);

it('follows the MCP origin (staging serves its own icon)', function () {
    config(['mcp.resource_url' => 'https://mcp.staging.example.test/mcp']);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertJsonPath('logo_uri', 'https://mcp.staging.example.test/icon.png');
});
