<?php

use Illuminate\Support\Carbon;

/*
 * Blackbox B13: HSTS (one year, subdomains) and nosniff on every answer
 * from the API, and /.well-known/security.txt (RFC 9116).
 */

it('sends HSTS with includeSubDomains and nosniff on API answers, errors included', function (string $url, int $status) {
    $this->getJson($url)
        ->assertStatus($status)
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
})->with([
    'public' => ['/api/v1/plans', 200],
    'unauthenticated' => ['/api/v1/me', 401],
    'not found' => ['/api/v1/no-such-thing', 404],
]);

it('sends them on OAuth discovery and web routes too', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('serves security.txt with a contact and an expiry a year out', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00', 'UTC'));
    config(['app.url' => 'https://api.creatif.space']);

    $response = $this->get('/.well-known/security.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    expect($response->getContent())
        ->toContain('Contact: mailto:hello@creatif.space')
        ->toContain('Expires: 2027-11-13T00:00:00.000Z')
        ->toContain('Canonical: https://api.creatif.space/.well-known/security.txt');

    Carbon::setTestNow();
});
