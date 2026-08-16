<?php

/**
 * The `?next=` handoff on the way out to Google.
 *
 * The dashboard guard sends a logged-out visitor to /login?next=/spaces, and
 * the frontend passes that along here so the visitor lands back where they
 * were headed. It travels through the session for the same reason the
 * referral code does — the value belongs to the Next.js origin, and Laravel
 * only ever sees what is put in the URL.
 *
 * The return leg is covered by SafePathTest: mocking a full Socialite
 * callback would test Socialite, not this.
 */
it('remembers where the visitor was headed', function () {
    $this->get('/auth/google/redirect?next=/spaces')
        ->assertSessionHas('next_path', '/spaces');
});

it('keeps a query string on the way through', function () {
    $this->get('/auth/google/redirect?next='.urlencode('/space-editor?space=01HZX'))
        ->assertSessionHas('next_path', '/space-editor?space=01HZX');
});

it('never remembers a destination that leaves our origin', function (string $hostile) {
    $this->get('/auth/google/redirect?next='.urlencode($hostile))
        ->assertSessionHas('next_path', '/');
})->with([
    'absolute' => 'https://evil.test/steal',
    'protocol relative' => '//evil.test/steal',
    'mixed slashes' => '/\\evil.test',
]);

it('stores nothing when no destination is asked for', function () {
    $this->get('/auth/google/redirect')
        ->assertSessionMissing('next_path');
});

it('carries the referral code and the destination together', function () {
    $this->get('/auth/google/redirect?ref=RANI10&next=/spaces')
        ->assertSessionHas('referral_code', 'RANI10')
        ->assertSessionHas('next_path', '/spaces');
});
