<?php

namespace App\Enums;

/**
 * The five switches on Settings → Notifications, plus the always-on set.
 * Every switchable type has a notification that actually raises it.
 */
enum NotificationType: string
{
    case ApprovalDecided = 'approval.decided';
    case SpaceOpened = 'space.opened';
    case FilesReceived = 'files.received';
    case SocialPostFailed = 'social.post.failed';
    case ProductNews = 'product.news';

    // Money. Deliberately absent from the Settings screen: "your
    // subscription lapses in 7 days" is not a preference, and neither is
    // "someone paid you". Silencing either would mean losing track of money
    // without being told. See switchable().
    case BillingReminder = 'billing.reminder';
    case BillingFailed = 'billing.failed';
    case OrderPaid = 'order.paid';

    // Storage nearly/completely full is not a preference either: silencing
    // it would mean client uploads start bouncing without a word.
    case StorageQuota = 'storage.quota';

    /**
     * The types a user may switch off — what Settings → Notifications shows.
     *
     * @return list<self>
     */
    public static function switchable(): array
    {
        return [
            self::ApprovalDecided,
            self::SpaceOpened,
            self::FilesReceived,
            self::SocialPostFailed,
            self::ProductNews,
        ];
    }
}
