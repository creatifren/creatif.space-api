<?php

use Illuminate\Support\Facades\Route;

/**
 * Every error under api/* is JSON, whatever the client asked for — the
 * SPA parses the body, and a browser tab or curl with no Accept header must
 * not get Laravel's HTML page. (bootstrap/app.php, shouldRenderJsonWhen.)
 *
 * Note: in production nginx must not swap these bodies for its own HTML
 * error page (proxy_intercept_errors / error_page on the API vhost).
 */
it('answers an unknown api route with a JSON 404, even without Accept: json', function () {
    $this->get('/api/v1/no-such-thing', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

it('answers a wrong method with a JSON 405', function () {
    $this->delete('/api/v1/plans', [], ['Accept' => 'text/html'])
        ->assertStatus(405)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

it('answers an unhandled exception with a JSON 500 that leaks nothing', function () {
    config(['app.debug' => false]);
    Route::get('/api/v1/__boom', fn () => throw new RuntimeException('secret detail'));

    $this->get('/api/v1/__boom', ['Accept' => 'text/html'])
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Server Error']);
});
