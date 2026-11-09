<?php

namespace App\Exceptions;

use RuntimeException;

/** The relay holding this account has no Inbox (DMs / comments) API. */
class InboxUnsupported extends RuntimeException
{
    public static function for(string $provider, string $what): self
    {
        return new self("{$provider} does not support {$what}. Reconnect the account through Outstand to use the Inbox.");
    }
}
