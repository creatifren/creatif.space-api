<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

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

it('is the single source of HSTS: one header, a year or more, subdomains included, even over a route\'s own', function () {
    Route::middleware('api')->get('/api/v1/__test/own-hsts', fn () => response()->json([])
        ->header('Strict-Transport-Security', 'max-age=300'));

    foreach (['/api/v1/plans', '/api/v1/__test/own-hsts'] as $url) {
        $values = $this->getJson($url)->headers->all('strict-transport-security');

        expect($values)->toHaveCount(1)
            ->and($values[0])->toBe(SecurityHeaders::HSTS)
            ->and($values[0])->toContain('includeSubDomains');
        preg_match('/max-age=(\d+)/', $values[0], $m);
        expect((int) $m[1])->toBeGreaterThanOrEqual(31536000);
    }
});
