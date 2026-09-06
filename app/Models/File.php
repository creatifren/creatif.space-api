<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * An asset hosted on our storage (R2 via the s3 disk).
 *
 * The row is the file: its ulid is what Space items and URLs point at, and
 * that never moves. The bytes underneath it can be replaced — the ones it
 * had before move to `file_versions`, so a replacement is reversible and
 * nothing that referenced the file has to be rewritten.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int|null $folder_id
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
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property \Carbon\CarbonImmutable|null $purge_at
 */
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    /* Deleting a file puts it in the Trash: the row and the R2 object both
       stay until PurgeTrashedFiles takes them, so a restore costs nothing.
       Trashed bytes keep counting against the quota — the object is still
       in the bucket and still billed, which is what makes "empty the Trash
       to free 340 MB" a true sentence. */
    use SoftDeletes;

    /** How long a trashed file waits before the purge job takes it. */
    public const TRASH_DAYS = 30;

    /**
     * R2 hands back an ETag; for a single-part upload it is the object's
     * MD5, which is the same thing Drive's `md5Checksum` gives us — so the
     * two sources are comparable and duplicate detection can span both.
     *
     * A multipart ETag is an MD5 of the parts' MD5s with a `-N` suffix. It
     * is stable per upload but says nothing about the bytes: the same file
     * uploaded with a different part size gets a different one. Storing it
     * would make two identical files look distinct and, worse, two
     * different files with matching part counts look identical. Null is the
     * honest answer for those.
     */
    public static function md5FromEtag(string|false|null $etag): ?string
    {
        if (! is_string($etag)) {
            return null;
        }

        $etag = trim($etag, '"');

        return preg_match('/^[0-9a-f]{32}$/i', $etag) === 1 ? strtolower($etag) : null;
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        // Only our own controllers/jobs write this model — API input never
        // mass-assigns it beyond validated fields.
        'ulid',
        'user_id',
        'folder_id',
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
            'purge_at' => 'datetime',
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

    /** How long a serving URL stays valid. */
    public const URL_TTL_HOURS = 24;

    /**
     * Serving URL — a signed GET, not a public one.
     *
     * This used to be `Storage::url()`: the bucket's own public address plus
     * the object key, which anyone could keep and share forever. Every gate
     * the backend has — a Space password, an expiry date, a file moved to
     * the Trash, `allow_download` — guarded the *page* and not the bytes, so
     * none of them actually withheld anything from someone who had the URL.
     *
     * Signed for URL_TTL_HOURS. Long enough that a gallery left open all
     * afternoon keeps working, short enough that a password changed today
     * means something tomorrow. No column: one source of truth, and a URL
     * that is stale by design rather than by accident.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addHours(self::URL_TTL_HOURS),
        );
    }

    /**
     * The same bytes, but as a download rather than something the browser
     * renders inline. Content-Disposition is signed into the URL, so R2
     * sends the original filename and the browser saves it.
     */
    public function downloadUrl(): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addHours(self::URL_TTL_HOURS),
            ['ResponseContentDisposition' => 'attachment; filename="'.addslashes($this->name).'"'],
        );
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

    /**
     * Older bytes, newest first. The current bytes are on this row and are
     * never duplicated here.
     *
     * @return HasMany<FileVersion, $this>
     */
    /** The folder it sits in — null at the library root. */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class)->orderByDesc('number');
    }

    /**
     * What the next version to be filed away should be numbered. v1 is the
     * first bytes ever stored, so a file with no history is already at 1.
     */
    public function currentVersionNumber(): int
    {
        return (int) $this->versions()->max('number') + 1;
    }
}
