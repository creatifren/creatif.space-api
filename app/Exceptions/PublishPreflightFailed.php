<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A post one of its networks would refuse, caught before it is charged or
 * sent. Rendered as a 422 naming each destination and what to fix.
 */
class PublishPreflightFailed extends RuntimeException
{
    public const CODE = 'publish_preflight_failed';

    /**
     * @param  list<array{account_id: string, platform: string, code: string, reason: string}>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct($problems[0]['reason'] ?? 'This post cannot go to one of its channels yet.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'problems' => $this->problems,
            'errors' => ['account_ids' => array_values(array_unique(array_column($this->problems, 'reason')))],
        ], 422);
    }
}
