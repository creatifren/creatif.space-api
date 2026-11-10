<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Provider values into our columns without a 1406 ("Data too long"):
 * names are clamped to the column, a URL longer than 2048 (the URL
 * columns are TEXT) or not http(s) becomes null, and an id that does not fit is refused — a
 * truncated id would point at the wrong thing.
 */
final class ProviderFields
{
    /** Clamp display text (usernames, names) to the column. */
    public static function text(mixed $value, int $max = 255): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, $max);
    }

    /** An http(s) URL up to $max chars (URL columns are TEXT), or null. */
    public static function url(mixed $value, int $max = 2048): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $max || ! preg_match('#^https?://#i', $value)) {
            return null;
        }

        return $value;
    }

    /** An id that fits, or null (logged): the caller skips the row. */
    public static function id(mixed $value, int $max, string $what): ?string
    {
        $id = is_scalar($value) ? (string) $value : '';
        if ($id === '') {
            return null;
        }
        if (mb_strlen($id) > $max) {
            Log::warning('social.provider_id_too_long', ['field' => $what, 'length' => mb_strlen($id), 'max' => $max]);

            return null;
        }

        return $id;
    }

    /**
     * A provider account (SocialProvider shape) for social_accounts, or null
     * when its id does not fit provider_account_id (64).
     *
     * @param  array<string, mixed>  $account
     * @return array{provider_account_id: string, username: string|null, profile_photo_url: string|null, network_unique_id: string|null, network_webhook_reference_id: string|null}|null
     */
    public static function account(array $account): ?array
    {
        $id = self::id($account['id'] ?? null, 64, 'social_accounts.provider_account_id');
        if ($id === null) {
            return null;
        }

        return [
            'provider_account_id' => $id,
            'username' => self::text($account['username'] ?? null),
            'profile_photo_url' => self::url($account['profile_photo_url'] ?? null),
            'network_unique_id' => self::text($account['network_unique_id'] ?? null, 191),
            'network_webhook_reference_id' => self::text($account['network_webhook_reference_id'] ?? null, 64),
        ];
    }
}
