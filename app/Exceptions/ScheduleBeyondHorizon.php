<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A slot further out than the publishing relay accepts (Outstand refuses a
 * schedule past its horizon). Since 18 Nov 2026 no owner or client path
 * answers with it: past the horizon a post is held here and sent once it
 * is inside (SocialPostRelay::sendOrHold, SendApprovedHeldPosts). It stays
 * as SocialPostRelay::send's last guard — a slot past the horizon is never
 * charged and handed to the relay.
 */
class ScheduleBeyondHorizon extends RuntimeException
{
    public const CODE = 'schedule_horizon';

    public function __construct(
        public readonly int $days,
        public readonly CarbonImmutable $latest,
        public readonly string $field = 'scheduled_at',
    ) {
        parent::__construct(
            "Posts can be scheduled at most {$days} days ahead — the latest date that works now is "
                .$latest->format('j M Y').'. Pick an earlier time, or schedule it closer to the date.'
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'max_days' => $this->days,
            'max_date' => $this->latest->toIso8601String(),
            'errors' => [$this->field => [$this->getMessage()]],
        ], 422);
    }
}
