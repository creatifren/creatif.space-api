<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A built (or building) "Download all" zip for one Space.
 *
 * @property int $id
 * @property string $ulid
 * @property int $space_id
 * @property string $signature
 * @property string $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $failure_reason
 * @property \Carbon\CarbonImmutable|null $expires_at
 */
class SpaceArchive extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** How long a built archive is kept before the cleanup job takes it. */
    public const TTL_HOURS = 24;

    /**
     * Everything above this and we do not build: the bytes have to land on
     * the server's disk before they can be zipped, and a 25 GB Space would
     * take the disk and the queue with it. Matches the Free plan's whole
     * ceiling, so it is a limit most Spaces never meet.
     */
    public const MAX_BYTES = 2 * 1024 ** 3;

    protected $fillable = [
        'ulid', 'space_id', 'signature', 'status', 'path', 'size_bytes',
        'failure_reason', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $archive): void {
            $archive->ulid ??= (string) str()->ulid();
        });
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * What the Space held when this was built. A published photo changes it,
     * so the next visitor gets a fresh build rather than an archive that is
     * quietly missing a file.
     */
    public static function signatureFor(Space $space): string
    {
        return hash('sha256', $space->items()->pluck('ulid')->implode(','));
    }

    /** Signed like every other object we serve — see File::url(). */
    public function url(): ?string
    {
        if ($this->path === null) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(
            $this->path,
            now()->addHours(File::URL_TTL_HOURS),
            ['ResponseContentDisposition' => 'attachment; filename="'.addslashes($this->filename()).'"'],
        );
    }

    public function filename(): string
    {
        return str($this->space?->title ?? 'space')->slug()->value().'.zip';
    }
}
