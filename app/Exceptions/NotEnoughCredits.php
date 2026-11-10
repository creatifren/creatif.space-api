<?php

namespace App\Exceptions;

use App\Models\User;
use App\Support\Credits;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A send the wallet cannot pay for, refused before anything is charged or
 * reaches the provider. Two codes, both 422 with the old `caption` field
 * error the app shows:
 *
 * - quota_exceeded: this cycle's Monthly Credits are used up and there is
 *   no Top-up to fall back on — the plan's allowance resets on a date.
 * - insufficient_credits: anything else that is short (needed vs available).
 */
class NotEnoughCredits extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $needed,
        public readonly int $monthly,
        public readonly int $topup,
        public readonly string $resetsAt,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  int  $price  the full price of the send
     */
    public static function for(User $user, int $price): self
    {
        $owner = Workspace::owner($user);
        $monthly = Credits::monthlyBalance($user);
        $topup = Credits::balance($user);
        $resets = Credits::cycleEnds($owner);
        $url = self::topUpUrl();

        if ($monthly === 0 && $topup === 0) {
            return new self(
                'quota_exceeded',
                $price,
                $monthly,
                $topup,
                $resets->toIso8601String(),
                "This month's Credits on the {$owner->plan()->name} plan are used up — they reset on "
                    .$resets->format('j M Y').". This send needs {$price} Credits. Top up now: {$url}",
            );
        }

        $available = $monthly + $topup;

        return new self(
            'insufficient_credits',
            $price,
            $monthly,
            $topup,
            $resets->toIso8601String(),
            "Not enough Credits: this send needs {$price} and {$available} are available "
                ."({$monthly} monthly + {$topup} top-up). Top up: {$url}",
        );
    }

    public static function topUpUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/credits';
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'needed' => $this->needed,
            'available' => $this->monthly + $this->topup,
            'monthly_balance' => $this->monthly,
            'topup_balance' => $this->topup,
            'resets_at' => $this->resetsAt,
            'topup_url' => self::topUpUrl(),
            'errors' => ['caption' => [$this->getMessage()]],
        ], 422);
    }
}
