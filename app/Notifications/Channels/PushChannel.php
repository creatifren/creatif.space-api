<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Fcm;
use Illuminate\Notifications\Notification;

/**
 * Push to every phone the person registered (FCM). A notification takes
 * part by implementing toPush(): ['title' => …, 'body' => …, 'url' => …];
 * `url` is an app path the tap opens.
 */
class PushChannel
{
    /**
     * [PushChannel::class] when this person can get a push at all: FCM is
     * set up, the switch is on and a phone is registered.
     *
     * @return list<class-string>
     */
    public static function when(object $notifiable): array
    {
        if (! $notifiable instanceof User || ! $notifiable->push_enabled || ! Fcm::configured()) {
            return [];
        }

        return $notifiable->deviceTokens()->exists() ? [self::class] : [];
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toPush')) {
            return;
        }
        /** @var array{title: string, body: string, url: string} $push */
        $push = $notification->toPush($notifiable);
        $notifiable->deviceTokens()->get()->each(function (DeviceToken $device) use ($push): void {
            $result = Fcm::send($device->token, $push['title'], $push['body'], ['url' => $push['url']]);
            if ($result === Fcm::GONE) {
                $device->delete();
            }
        });
    }
}
