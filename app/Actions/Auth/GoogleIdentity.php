<?php

namespace App\Actions\Auth;

/**
 * Who Google says is signing in, whichever way the answer arrived: the
 * Socialite redirect callback on the web, or a verified ID token from the
 * Android app's Credential Manager.
 */
final readonly class GoogleIdentity
{
    public function __construct(
        /** Google's stable account id (`sub`). */
        public string $id,
        public string $email,
        public ?string $name = null,
        public ?string $avatar = null,
    ) {}
}
