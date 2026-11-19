<?php

namespace App\Support;

use App\Enums\PlanKey;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * v7 public profile rules by plan (decisions › Public URLs): Pro + Max get
 * extra fonts and a custom hex colour; Free keeps Lora + Inter and three
 * colour presets. A custom domain is Enterprise only (pricing page; Rizal
 * 19 Nov).
 */
final class ProfileAccess
{
    public const FREE_PALETTES = ['Paper', 'Stone', 'Forest'];

    public const FREE_FONTS = ['Lora'];

    public static function paid(User $user): bool
    {
        return Workspace::owner($user)->plan()->key !== PlanKey::Free;
    }

    /**
     * Free never stores custom colour sets: the editor sends them with every
     * save, but only the "Custom" palette (Pro + Max) reads them.
     *
     * @param  array<string, mixed>  $appearance
     * @return array<string, mixed>
     */
    public static function stripCustom(array $appearance): array
    {
        unset($appearance['custom_light'], $appearance['custom_dark']);

        return $appearance;
    }

    public static function customDomain(User $user): bool
    {
        return Workspace::owner($user)->plan()->key === PlanKey::Enterprise;
    }

    /** @param  array<string, mixed>  $appearance */
    public static function assertFreeAppearance(array $appearance): void
    {
        $palette = $appearance['palette'] ?? null;
        if ($palette !== null && ! in_array($palette, self::FREE_PALETTES, true)) {
            throw ValidationException::withMessages(['appearance.palette' => 'A custom colour is on Pro and Max. Free has Paper, Stone and Forest.']);
        }
        $font = $appearance['font'] ?? null;
        if ($font !== null && ! in_array($font, self::FREE_FONTS, true)) {
            throw ValidationException::withMessages(['appearance.font' => 'Extra fonts are on Pro and Max. Free uses Lora and Inter.']);
        }
    }
}
