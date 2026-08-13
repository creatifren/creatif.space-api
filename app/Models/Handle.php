<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HandleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property bool $is_reserved
 * @property int|null $previous_user_id
 * @property CarbonImmutable|null $released_at
 * @property-read User|null $user
 */
class Handle extends Model
{
    /** @use HasFactory<HandleFactory> */
    use HasFactory;

    /**
     * Handles a creator may never claim, seeded and kept in sync with the
     * frontend's route names (see ReservedHandleSeeder).
     */
    protected $fillable = [
        'user_id',
        'name',
        'is_reserved',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_reserved' => 'boolean',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalise raw input into handle shape: lowercase, a-z 0-9 hyphen, max 30.
     */
    public static function normalize(string $raw): string
    {
        return substr(preg_replace('/[^a-z0-9-]/', '', strtolower($raw)) ?? '', 0, 30);
    }

    /**
     * A handle is available when it does not exist, or exists released and
     * out of its 30-day grace window (still owned by no one, not reserved).
     */
    public static function isAvailable(string $name): bool
    {
        $existing = static::query()->where('name', $name)->first();

        if ($existing === null) {
            return true;
        }

        return ! $existing->is_reserved
            && $existing->user_id === null
            && $existing->released_at !== null
            && $existing->released_at->addDays(30)->isPast();
    }
}
