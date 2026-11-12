<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A retry asked for on a post that has nothing to retry (no failed
 * destination: not_failed) or that went out through a relay this one
 * cannot re-send (not_retryable). Rendered as a 409 with the code, like
 * every other state conflict.
 */
class PostNotRetryable extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notFailed(): self
    {
        return new self('not_failed', 'Nothing to retry: no destination of this post failed.');
    }

    public static function otherRelay(): self
    {
        return new self('not_retryable', 'This post went out through an earlier publishing service and cannot be retried. Duplicate it and send the copy.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], 409);
    }
}
