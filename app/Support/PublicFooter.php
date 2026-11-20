<?php

namespace App\Support;

use App\Enums\PlanKey;
use App\Models\User;

/**
 * The footer on every public page — profile, Gallery, Blog, approval link,
 * content plan, report (backlog #2, Rizal 20 Nov; prototype v9.1
 * `footerHtml`). Every plan shows it; only Enterprise removes it.
 *
 *   full = Free: the slogan + "Made with Creatif Space"
 *   line = Pro / Max: one small grey "Made with Creatif Space" line
 *   none = Enterprise
 *
 * The link is creatif.space/?ref=footer&from={username}, so a signup from
 * a client's page is credited to the page's owner. The plan itself never
 * leaves the server — only which footer to draw.
 */
final class PublicFooter
{
    public const FULL = 'full';

    public const LINE = 'line';

    public const NONE = 'none';

    public static function kind(?User $user): string
    {
        if ($user === null) {
            return self::FULL;
        }

        return match (Workspace::owner($user)->plan()->key) {
            PlanKey::Enterprise => self::NONE,
            PlanKey::Free => self::FULL,
            default => self::LINE,
        };
    }

    public static function url(?User $user): string
    {
        $handle = $user?->handle?->name;

        return 'https://creatif.space/?ref=footer'.($handle ? '&from='.rawurlencode($handle) : '');
    }

    /** @return array{footer: string, footer_url: string} */
    public static function fields(?User $user): array
    {
        return ['footer' => self::kind($user), 'footer_url' => self::url($user)];
    }

    /**
     * The owner's public contact for a dead link page ("Contact Rizal on
     * WhatsApp" / "Email Rizal"): only what they put on their public
     * profile's Contact button, nothing else.
     *
     * @return array{whatsapp: ?string, email: ?string}
     */
    public static function contact(?User $user): array
    {
        $c = (array) ($user?->profile->contact ?? []);

        return [
            'whatsapp' => isset($c['whatsapp']) && $c['whatsapp'] !== '' ? (string) $c['whatsapp'] : null,
            'email' => isset($c['email']) && $c['email'] !== '' ? (string) $c['email'] : null,
        ];
    }
}
