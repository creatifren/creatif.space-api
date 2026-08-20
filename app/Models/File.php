<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * An asset hosted on our storage (R2 via the s3 disk). Immutable once
 * ready — replacing a file means a new row.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime_type
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property string $status
 * @property int|null $width
 * @property int|null $height
 * @property array<string, mixed>|null $exif
 * @property string $source
 * @property array<string, mixed>|null $source_meta
 */
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        // Only our own controllers/jobs write this model — API input never
        // mass-assigns it beyond validated fields.
        'ulid',
        'user_id',
        'disk',
        'path',
        'name',
        'mime_type',
        'size_bytes',
        'checksum',
        'status',
        'width',
        'height',
        'exif',
        'source',
        'source_meta',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exif' => 'array',
            'source_meta' => 'array',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $file): void {
            $file->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Public serving URL — AWS_URL + object key. No column: one source
     * of truth, no stale URLs.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Object key for a user-owned asset.
     */
    public static function keyFor(User $user, string $ulid, string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return 'u/'.$user->ulid.'/'.$ulid.($ext !== '' ? '.'.$ext : '');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SpaceItem, $this>
     */
    public function spaceItems(): HasMany
    {
        return $this->hasMany(SpaceItem::class);
    }
}
