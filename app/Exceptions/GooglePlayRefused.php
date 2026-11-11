<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A Play purchase we will not grant a plan for. `reason` is what the app
 * reads: invalid_token, unknown_product, account_mismatch,
 * active_web_subscription, active_play_subscription.
 */
class GooglePlayRefused extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidToken(): self
    {
        return new self(422, 'invalid_token', 'That purchase isn’t valid on Google Play.');
    }

    public static function unknownProduct(): self
    {
        return new self(422, 'unknown_product', 'That product isn’t a creatif.space plan.');
    }

    public static function accountMismatch(): self
    {
        return new self(403, 'account_mismatch', 'That purchase belongs to another creatif.space account.');
    }

    public static function activeWebSubscription(): self
    {
        return new self(409, 'active_web_subscription', 'You already have a plan from the web. Manage it at creatif.space.');
    }

    public static function activePlaySubscription(): self
    {
        return new self(409, 'active_play_subscription', 'You already have a plan from Google Play. Manage it in the Play Store.');
    }

    /** Expected outcomes, not errors. */
    public function report(): void {}

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->status);
    }
}
