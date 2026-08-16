<?php

namespace App\Support;

/**
 * Where an OAuth round trip is allowed to land.
 *
 * Both Google flows take a destination from the query string — the creator's
 * `?next=` and the approver's `?return=` — and both hand it back as a redirect
 * once Google answers. That makes the value attacker-supplied all the way
 * through, so it is checked on the way in and again on the way out: an open
 * redirect here would let a stranger's link bounce off our own domain and
 * arrive somewhere else wearing our name.
 *
 * Only a path on our own frontend survives. Anything else becomes `/`.
 */
final class SafePath
{
    /**
     * The path if it is one of ours, `/` otherwise.
     *
     * Rejected, and why:
     *
     * - `https://evil.test/x` — absolute, a different origin outright.
     * - `//evil.test/x` — protocol-relative; the browser reads the part
     *   after `//` as a host, so this leaves our site despite looking
     *   like a path.
     * - `/\evil.test` and `\/evil.test` — browsers normalise a backslash
     *   to a forward slash in the authority position, which makes these
     *   protocol-relative too by the time they are followed.
     */
    public static function of(mixed $path): string
    {
        if (! is_string($path) || $path === '') {
            return '/';
        }

        // Normalise first, then test: the check has to see what the browser
        // will see, not what was typed.
        $normalised = str_replace('\\', '/', $path);

        return str_starts_with($normalised, '/') && ! str_starts_with($normalised, '//')
            ? $path
            : '/';
    }
}
