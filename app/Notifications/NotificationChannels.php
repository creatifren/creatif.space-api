<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;

/**
 * Which channels this person still wants for a given type. The product
 * promise is that every one of the five switches genuinely silences the
 * thing — so the preference is read here, once, rather than remembered
 * separately by each notification.
 */
class NotificationChannels
{
    /**
     * @return list<string>
     */
    public static function for(object $notifiable, NotificationType $type): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        $channels = [];

        if ($notifiable->wants($type, 'email')) {
            $channels[] = 'mail';
        }

        if ($notifiable->wants($type, 'bell')) {
            $channels[] = 'database';
        }

        return $channels;
    }
}
