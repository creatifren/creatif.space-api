<?php

use App\Support\SafePath;

/*
 * The one job: an OAuth round trip must never bounce a visitor off our own
 * domain. Both Google flows feed attacker-supplied values through here.
 */

it('keeps a path on our own frontend', function (string $path) {
    expect(SafePath::of($path))->toBe($path);
})->with([
    '/home',
    '/spaces',
    '/space-editor?space=01HZX',
    '/rani/winter-noel',
    '/',
]);

it('refuses anything that leaves our origin', function (mixed $path) {
    expect(SafePath::of($path))->toBe('/');
})->with([
    'absolute' => 'https://evil.test/steal',
    'absolute http' => 'http://evil.test',
    // Protocol-relative: the browser reads what follows // as a host.
    'protocol relative' => '//evil.test/steal',
    // Browsers normalise a backslash in the authority position to a slash,
    // so these are protocol-relative by the time they are followed.
    'backslash pair' => '\\\\evil.test',
    'mixed slashes' => '/\\evil.test',
    'scheme relative backslash' => '\\/evil.test',
    // Not a path at all.
    'bare host' => 'evil.test',
    'empty' => '',
    'not a string' => null,
    'integer' => 123,
    // Wrapped twice: a dataset row is spread as arguments, so a bare
    // ['/home'] would arrive as the string, not as the array under test.
    'array' => [['/home']],
]);
