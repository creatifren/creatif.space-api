<?php

namespace App\Models;

use App\Enums\FileRequestStatus;
use App\Enums\SubmissionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\FileRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A link that collects files into the owner's library.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $slug
 * @property string $title
 * @property string|null $note
 * @property string|null $password_hash
 * @property int $max_files
 * @property int $max_mb
 * @property FileRequestStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
class FileRequest extends Model
{
    /** @use HasFactory<FileRequestFactory> */
    use HasFactory;

    /**
     * A request closes itself here. Somebody's link went further than they
     * meant it to, and an open drop-box is somebody else's storage bill.
     */
    public const SUBMISSION_CEILING = 50;

    /** How long a link lives when the owner names no date. */
    public const DEFAULT_DAYS = 30;

    /**
     * Ceilings on what the owner may ask for. The count is the column's own
     * (unsignedTinyInteger, and the request closes itself at 50 submissions
     * anyway); the size is the same per-file ceiling a direct upload gets,
     * because a stranger's file lands in exactly the same library.
     */
    public const MAX_FILES_CEILING = 50;

    public const MAX_MB_CEILING = 500;

    protected $fillable = [
        'title',
        'note',
        'max_files',
        'max_mb',
        'expires_at',
    ];

    /** Never serialised: the hash is the owner's secret, not the page's. */
    protected $hidden = ['password_hash'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FileRequestStatus::class,
            'max_files' => 'integer',
            'max_mb' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID and the short public slug on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->ulid ??= (string) str()->ulid();
            $request->slug ??= static::generateSlug();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<FileRequestSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FileRequestSubmission::class);
    }

    /**
     * The deliveries that actually landed. A submission still uploading has
     * no files yet and a failed one never will, so neither belongs in a
     * count of what arrived.
     *
     * @return \Illuminate\Support\Collection<int, FileRequestSubmission>
     */
    public function storedSubmissions(): \Illuminate\Support\Collection
    {
        return $this->submissions
            ->where('status', SubmissionStatus::Stored)
            ->values();
    }

    public function isOpen(): bool
    {
        return $this->status === FileRequestStatus::Open;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Ten lowercase characters — short enough to read out over the phone,
     * long enough that nobody finds one by guessing.
     */
    public static function generateSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(10));
        } while (static::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
