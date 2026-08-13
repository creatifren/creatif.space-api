<?php

namespace App\Models;

use App\Enums\FileRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\FileRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A link that collects files into the owner's Drive.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $slug
 * @property string $title
 * @property string|null $note
 * @property int $drive_account_id
 * @property string $target_folder_id
 * @property int $max_files
 * @property int $max_mb
 * @property FileRequestStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read DriveAccount $driveAccount
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

    protected $fillable = [
        'title',
        'note',
        'drive_account_id',
        'target_folder_id',
        'max_files',
        'max_mb',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        // The folder id is the owner's Drive, not the sender's business.
        'target_folder_id',
    ];

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
     * @return BelongsTo<DriveAccount, $this>
     */
    public function driveAccount(): BelongsTo
    {
        return $this->belongsTo(DriveAccount::class);
    }

    /**
     * @return HasMany<FileRequestSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FileRequestSubmission::class);
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
