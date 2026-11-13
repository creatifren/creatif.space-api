<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A post with no publish time never goes to a client: they could not
 * approve it (an approved post goes out at its time, and it has none) and
 * would hit a dead end. 422 with the posts it names and the next step.
 */
class NeedsPublishTime extends RuntimeException
{
    public const CODE = 'needs_publish_time';

    public const NEXT_STEP = 'Set a publish time first: update_post with scheduled_at (or schedule_post), or pick a time in the composer, then send it for approval again.';

    /**
     * @param  list<string>  $postIds  the posts without a time (ulids)
     */
    public function __construct(private array $postIds, private string $field)
    {
        parent::__construct(count($postIds) === 1
            ? 'This post has no publish time yet. Set one before you send it for approval — the client approves it for that time.'
            : 'These posts have no publish time yet. Set one on each before you send them for approval — the client approves them for that time.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'next_step' => self::NEXT_STEP,
            'post_ids' => $this->postIds,
            'errors' => [$this->field => [$this->getMessage()]],
        ], 422);
    }
}
