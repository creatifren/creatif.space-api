<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * A datetime column that holds UTC wall time, whatever the app timezone.
 *
 * The app runs in Asia/Jakarta and Eloquent's `datetime` cast reads a
 * column as that zone's wall clock. scheduled_at has always been written
 * as UTC (the client sends "…Z"; prod 12 Nov: 08:30 MVT stored as
 * 03:30:00) and read back as Jakarta — every slot came back 7 h early, in
 * the API and in the approve/send due-time checks. This cast pins both
 * sides: in, any instant ("…Z", "+05:00", a Carbon in any zone) is moved
 * to UTC; out, the stored value is read as UTC. Serialises as ISO 8601
 * with its offset (…Z).
 *
 * A query that compares the column binds UTC too: UtcDateTime::bind().
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
class UtcDateTime implements CastsAttributes
{
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::bind($value instanceof DateTimeInterface ? $value : Date::parse((string) $value));
    }

    /**
     * An instant as the column stores it — for where() bindings against a
     * UTC column (a Carbon bound as-is is written in its own zone).
     */
    public static function bind(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format(self::FORMAT);
    }
}
