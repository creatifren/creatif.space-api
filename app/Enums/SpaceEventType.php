<?php

namespace App\Enums;

/**
 * What a visitor did. Only `view` counts toward the headline number —
 * the rest are how that view is broken down.
 */
enum SpaceEventType: string
{
    case View = 'view';
    case LightboxOpen = 'lightbox_open';
    case Download = 'download';
    case PasswordPass = 'password_pass';
    case OrderClick = 'order_click';

    /**
     * The types the public beacon accepts. Password and order events are
     * recorded server-side where they actually happen, so a browser must
     * not be able to claim them.
     *
     * @return list<string>
     */
    public static function beaconable(): array
    {
        return [
            self::View->value,
            self::LightboxOpen->value,
            self::Download->value,
        ];
    }
}
