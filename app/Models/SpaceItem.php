<?php

namespace App\Models;

use Database\Factories\SpaceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $ulid
 * @property int $space_id
 * @property int $drive_file_id
 * @property string|null $section
 * @property int $sort_order
 * @property string|null $caption
 */
class SpaceItem extends Model
{
    /** @use HasFactory<SpaceItemFactory> */
    use HasFactory;

    protected $fillable = [
        'drive_file_id',
        'section',
        'sort_order',
        'caption',
    ];

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return BelongsTo<DriveFile, $this>
     */
    public function driveFile(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
}
