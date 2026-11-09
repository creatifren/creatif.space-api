<?php

namespace App\Exceptions;

use RuntimeException;

/** The relay asked us to wait (429, or X-RateLimit-Remaining hit 0). */
class ProviderRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Rate limited by the provider; retry in {$retryAfter}s.");
    }
}
