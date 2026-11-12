<?php

namespace App\Models;

use App\Models\Contracts\WorkspaceOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One Insight PDF export (design pick #8). Built by BuildInsightReport.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $owner_id
 * @property string $client_id
 * @property string $format onepage|deck|details
 * @property string $locale en|id
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property array{channel_ids: list<string>, show_logo: bool, hide_branding: bool, next_month_notes: string|null, period_kind: string} $options
 * @property string $status queued|building|done|failed
 * @property string|null $file_name
 * @property string|null $path
 * @property int|null $size_bytes
 * @property int|null $page_count
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 */
class InsightReport extends Model implements WorkspaceOwned
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_BUILDING = 'building';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const FORMATS = ['onepage', 'deck', 'details'];

    public const LOCALES = ['en', 'id'];

    /** The stored file is kept this long; the row stays as the receipt. */
    public const TTL_HOURS = 24;

    /** download_url is signed for this long — fetch it again for a new one. */
    public const URL_TTL_MINUTES = 15;

    public const DISK = 's3';

    protected $fillable = [
        'user_id',
        'owner_id',
        'client_id',
        'format',
        'locale',
        'period_from',
        'period_to',
        'options',
        'status',
        'file_name',
        'path',
        'size_bytes',
        'page_count',
        'failure_reason',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'options' => 'array',
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->ulid ??= (string) str()->ulid();
        });
    }

    /** The workspace a report belongs to is its owner's (ScopeToWorkspace). */
    public function workspaceUserId(): ?int
    {
        return $this->owner_id;
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Signed, short-lived; null until the file exists (or after it expired). */
    public function downloadUrl(): ?string
    {
        if ($this->status !== self::STATUS_DONE || $this->path === null || $this->expired()) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $this->path,
            now()->addMinutes(self::URL_TTL_MINUTES),
            [
                'ResponseContentType' => 'application/pdf',
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes((string) $this->file_name).'"',
            ],
        );
    }
}
