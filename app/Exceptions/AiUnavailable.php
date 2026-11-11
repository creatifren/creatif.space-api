<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The AI provider is not set up (503) or did not answer usefully (502). */
class AiUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }

    public static function notSetUp(): self
    {
        return new self('AI is not set up.', 503);
    }

    public static function noAnswer(): self
    {
        return new self('The AI did not answer — try again.', 502);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
