<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DriveFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metadata cache of a file in the user's Drive. The bytes never come here.
 *
 * @property int $id
 * @property string $ulid
 * @property int $drive_account_id
 * @property string $provider_file_id
 * @property string|null $parent_folder_id
 * @property string $name
 * @property string $mime_type
 * @property int|null $size_bytes
 * @property string|null $thumbnail_url
 * @property string|null $version_hash
 * @property array<string, mixed>|null $exif
 * @property bool $is_folder
 * @property CarbonImmutable|null $access_lost_at
 * @property CarbonImmutable|null $trashed_at
 * @property CarbonImmutable|null $last_synced_at
 */
class DriveFile extends Model
{
    /** @use HasFactory<DriveFileFactory> */
    use HasFactory;

    protected $fillable = [
        // Only sync jobs write this model — API input never mass-assigns it.
        'drive_account_id',
        'provider_file_id',
        'parent_folder_id',
        'name',
        'mime_type',
        'size_bytes',
        'thumbnail_url',
        'version_hash',
        'exif',
        'is_folder',
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
            'is_folder' => 'boolean',
            'access_lost_at' => 'datetime',
            'trashed_at' => 'datetime',
            'last_synced_at' => 'datetime',
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
     * @return BelongsTo<DriveAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(DriveAccount::class, 'drive_account_id');
    }

    /**
     * @return HasMany<SpaceItem, $this>
     */
    public function spaceItems(): HasMany
    {
        return $this->hasMany(SpaceItem::class);
    }
}
