<?php

namespace App\Enums;

/**
 * The five switches on Settings → Notifications, plus the billing pair.
 * Of the switchable five, only the first two are sent in Fase 4; the rest
 * are stored preferences waiting for the events that raise them
 * (space_events and the digest job land in Fase 6).
 */
enum NotificationType: string
{
    case ApprovalDecided = 'approval.decided';
    case ApprovalCancelled = 'approval.cancelled';
    case SpaceOpened = 'space.opened';
    case WeeklyDigest = 'digest.weekly';
    case ProductNews = 'product.news';

    // Money. Deliberately absent from the Settings screen: "your
    // subscription lapses in 7 days" is not a preference, and neither is
    // "someone paid you". Silencing either would mean losing track of money
    // without being told. See switchable().
    case BillingReminder = 'billing.reminder';
    case BillingFailed = 'billing.failed';
    case OrderPaid = 'order.paid';

    /**
     * The types a user may switch off — what Settings → Notifications shows.
     *
     * @return list<self>
     */
    public static function switchable(): array
    {
        return [
            self::ApprovalDecided,
            self::ApprovalCancelled,
            self::SpaceOpened,
            self::WeeklyDigest,
            self::ProductNews,
        ];
    }
}
