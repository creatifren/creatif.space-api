<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Notifications\Channels\PushChannel;

/**
 * Prototype v10.8 (Rizal 21 Nov 2026): the bell gets everything, email only
 * what needs the person to act, push only the important ones.
 *
 * - Bell (database): always, for every type — no switches.
 * - Email: billing (failed payment, plan about to end) always; the
 *   "Problems that need you" types only while that one switch is on
 *   (stored as the email switch of social.post.failed, default on);
 *   nothing else (no per-approval, Space-opened or digest emails).
 * - Push: per event (PUSH_EVENTS), see push().
 */
class NotificationChannels
{
    /** Emails behind the "Problems that need you" switch. */
    public const PROBLEMS = [NotificationType::SocialPostFailed, NotificationType::CreditsLow, NotificationType::StorageQuota];

    /** Emails that cannot be switched off: money. */
    public const ALWAYS_EMAIL = [NotificationType::BillingFailed, NotificationType::BillingReminder];

    /** The five push switches (Settings › Notifications › Push on this phone). */
    public const PUSH_EVENTS = ['approved', 'changes', 'post_problem', 'channel', 'credits'];

    /**
     * @return list<string>
     */
    public static function for(object $notifiable, NotificationType $type): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        $channels = [];
        if (in_array($type, self::ALWAYS_EMAIL, true) || (in_array($type, self::PROBLEMS, true) && self::problemsEmail($notifiable))) {
            $channels[] = 'mail';
        }
        $channels[] = 'database';

        return $channels;
    }

    /** The "Problems that need you" email switch (default on). */
    public static function problemsEmail(User $user): bool
    {
        return $user->wants(NotificationType::SocialPostFailed, 'email');
    }

    /**
     * [PushChannel::class] when this person wants this push event and can get one.
     *
     * @return list<class-string>
     */
    public static function push(object $notifiable, string $event): array
    {
        if (! $notifiable instanceof User || ! $notifiable->pushWants($event)) {
            return [];
        }

        return PushChannel::when($notifiable);
    }
}
