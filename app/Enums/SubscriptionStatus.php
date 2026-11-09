<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    // Creem's trial: the plan's features, no charge yet. Ends paid (Active)
    // or, cancelled, back to Free at the trial's end.
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * The statuses that carry the plan: paid, trialing, or in the grace
     * window after a missed payment.
     *
     * @return list<self>
     */
    public static function entitled(): array
    {
        return [self::Active, self::Trialing, self::PastDue];
    }
}
