<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Google sign-in that proved who the person is, but that must not start
 * a session: `suspended`, or `google_not_linked` (a staff address matched
 * by email only). The reason is what the frontend reads.
 */
class GoogleLoginRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Google login refused: {$reason}");
    }
}
