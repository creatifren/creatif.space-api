<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Creem will not change a plan that is set to end (400
 * subscription_not_active) and resuming it did not work. An expected
 * outcome the app shows as is: never a 500.
 */
class SubscriptionNotActive extends RuntimeException
{
    public const CODE = 'subscription_not_active';

    public function __construct(public readonly ?string $endsAt, string $message)
    {
        parent::__construct($message);
    }

    public static function forPlan(string $planName, ?string $endsAt, ?string $endsOn): self
    {
        return new self($endsAt, $endsOn === null
            ? "Your {$planName} plan is set to end, so it can’t be changed right now. Choose the new plan again once it has ended, or contact support."
            : "Your {$planName} plan is set to end on {$endsOn}, so it can’t be changed right now. Choose the new plan again after that date, or contact support.");
    }

    public function report(): void {}

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'ends_at' => $this->endsAt,
        ], 409);
    }
}
