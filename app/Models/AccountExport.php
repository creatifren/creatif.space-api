<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One account data export (POST /me/export). Built by BuildAccountExport,
 * emailed as a 24-hour signed link. One running export per user.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $status queued | building | done | failed
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $error
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 */
class AccountExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_BUILDING = 'building';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const DISK = 's3';

    /** The download link in the email, and the stored ZIP, last this long. */
    public const LINK_TTL_HOURS = 24;

    /** Signed URLs for library files inside the export. */
    public const FILE_URL_TTL_DAYS = 7;

    protected $fillable = ['user_id', 'status', 'path', 'size_bytes', 'error', 'completed_at', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $export): void {
            $export->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Queued or building: at most one per user.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_BUILDING]);
    }

    public function available(): bool
    {
        return $this->status === self::STATUS_DONE && $this->path !== null
            && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** The emailed link: signed, no sign-in, until expires_at. */
    public function signedUrl(): string
    {
        return URL::temporarySignedRoute('api.v1.me.export.download', $this->expires_at ?? now(), ['export' => $this->ulid]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status,
            'size_bytes' => $this->size_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
