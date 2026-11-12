<?php

use Illuminate\Support\Facades\Http;

/**
 * G8 helper: `php artisan mcp:check-openai-challenge` says whether the
 * OpenAI Apps token is served — and never prints the token.
 */
const CHALLENGE_URL = 'https://mcp.creatif.space/.well-known/openai-apps-challenge';
const CHALLENGE_TOKEN = 'oai-secret-looking-token-123';

beforeEach(fn () => config(['mcp.resource_url' => 'https://mcp.creatif.space/mcp', 'mcp.openai_apps_challenge' => null]));

it('passes when the token is served as text/plain, and never prints it', function () {
    Http::fake([CHALLENGE_URL => Http::response(CHALLENGE_TOKEN, 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);

    $this->artisan('mcp:check-openai-challenge')
        ->expectsOutputToContain('Checking '.CHALLENGE_URL)
        ->expectsOutputToContain('OPENAI_APPS_CHALLENGE in this environment: not set')
        ->expectsOutputToContain('OK: served as text/plain.')
        ->doesntExpectOutputToContain(CHALLENGE_TOKEN)
        ->assertSuccessful();
});

it('compares with the local value by hash when it is set, without printing either', function (string $local, bool $ok) {
    config(['mcp.openai_apps_challenge' => $local]);
    Http::fake([CHALLENGE_URL => Http::response(CHALLENGE_TOKEN."\n", 200, ['Content-Type' => 'text/plain'])]);

    $run = $this->artisan('mcp:check-openai-challenge')
        ->expectsOutputToContain('OPENAI_APPS_CHALLENGE in this environment: set')
        ->doesntExpectOutputToContain(CHALLENGE_TOKEN)
        ->doesntExpectOutputToContain($local);
    $ok ? $run->expectsOutputToContain('matches OPENAI_APPS_CHALLENGE here')->assertSuccessful()
        : $run->expectsOutputToContain('does not match')->assertFailed();
})->with([
    'same token' => [CHALLENGE_TOKEN, true],
    'other token' => ['some-other-token-456', false],
]);

it('fails clearly when it is not served', function (int $status, string $type, string $body, string $says) {
    Http::fake([CHALLENGE_URL => Http::response($body, $status, ['Content-Type' => $type])]);

    $this->artisan('mcp:check-openai-challenge')->expectsOutputToContain($says)->assertFailed();
})->with([
    'unset (404)' => [404, 'text/plain', 'Not Found', 'Not served (404)'],
    'a redirect' => [302, 'text/html', '', 'Unexpected HTTP 302'],
    'html' => [200, 'text/html', '<html>', 'expected text/plain'],
    'empty' => [200, 'text/plain', '  ', 'Served, but empty'],
]);

it('takes --url for staging', function () {
    Http::fake(['https://mcp.staging.example/.well-known/openai-apps-challenge' => Http::response('x', 200, ['Content-Type' => 'text/plain'])]);

    $this->artisan('mcp:check-openai-challenge', ['--url' => 'https://mcp.staging.example/.well-known/openai-apps-challenge'])->assertSuccessful();
    Http::assertSent(fn ($r) => $r->url() === 'https://mcp.staging.example/.well-known/openai-apps-challenge');
});
