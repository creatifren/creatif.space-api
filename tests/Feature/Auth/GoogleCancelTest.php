<?php

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * BUG-001: pressing Cancel on Google's consent screen used to end in a 500.
 * Either way it fails, the visitor goes back to the login page with a reason
 * the frontend can show.
 */
beforeEach(function () {
    config(['app.frontend_url' => 'https://app.creatif.test']);
});

it('sends a cancelled consent back to the login page', function () {
    $this->get('/auth/google/callback?error=access_denied&state=abc')
        ->assertRedirect('https://app.creatif.test/login?error=google_cancelled');

    $this->assertGuest();
});

it('treats a callback with no code as cancelled', function () {
    $this->get('/auth/google/callback?state=abc')
        ->assertRedirect('https://app.creatif.test/login?error=google_cancelled');
});

it('treats a failed state check as cancelled, not a 500', function () {
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/auth/google/callback?code=stale&state=wrong')
        ->assertRedirect('https://app.creatif.test/login?error=google_cancelled');

    $this->assertGuest();
});
