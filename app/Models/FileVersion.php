<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Bytes a file used to have.
 *
 * The `files` row always holds the current bytes; these are what came
 * before, each with its own R2 object so restoring one is a copy, never a
 * download of something already overwritten.
 *
 * @property int $id
 * @property string $ulid
 * @property int $file_id
 * @property int $number
 * @property string $disk
 * @property string $path
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property int|null $width
 * @property int|null $height
 * @property array<string, mixed>|null $exif
 * @property int|null $user_id
 * @property string|null $note
 */
class FileVersion extends Model
{
    protected $fillable = [
        'ulid',
        'file_id',
        'number',
        'disk',
        'path',
        'size_bytes',
        'checksum',
        'width',
        'height',
        'exif',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return ['exif' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Where a version's bytes live. Nested under the file's own ulid so a
     * file and its history sit together in the bucket, and keyed by the
     * version ulid so no two versions can collide.
     */
    public static function keyFor(File $file, string $ulid, string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return 'v/'.$file->ulid.'/'.$ulid.($ext !== '' ? '.'.$ext : '');
    }
}
