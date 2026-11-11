<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Time on webhook_events rows. New rows hold UTC in created_at /
 * updated_at and say so (created_at_utc = true); rows written before hold
 * app-timezone (WIB) wall time and are read as such — never rewritten.
 */
final class WebhookEventTime
{
    /**
     * The timestamp columns for a new row.
     *
     * @return array{created_at: string, updated_at: string, created_at_utc: bool}
     */
    public static function stamp(): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        return ['created_at' => $now, 'updated_at' => $now, 'created_at_utc' => true];
    }

    /** When a stored row was received, as a correct instant whichever way it was written. */
    public static function receivedAt(object $row): ?CarbonImmutable
    {
        $raw = $row->created_at ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return CarbonImmutable::parse($raw, (bool) ($row->created_at_utc ?? false) ? 'UTC' : (string) config('app.timezone'));
    }
}
