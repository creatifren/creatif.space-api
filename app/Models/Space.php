<?php

namespace App\Models;

use App\Enums\SpaceStatus;
use App\Enums\SpaceViewMode;
use App\Enums\SpaceVisibility;
use Carbon\CarbonImmutable;
use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property SpaceStatus $status
 * @property SpaceVisibility $visibility
 * @property SpaceViewMode $view_mode
 * @property string|null $password_hash
 * @property CarbonImmutable|null $expires_at
 * @property bool $approval_enabled
 * @property array<string, mixed> $design
 * @property array<string, mixed> $settings
 * @property array<string, mixed>|null $seo
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $first_opened_at
 * @property CarbonImmutable|null $first_downloaded_at
 * @property int|null $downloaded_files
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Space extends Model
{
    /** @use HasFactory<SpaceFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'title',
        'description',
        'visibility',
        'view_mode',
        'expires_at',
        'approval_enabled',
        'design',
        'settings',
        'seo',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SpaceStatus::class,
            'visibility' => SpaceVisibility::class,
            'view_mode' => SpaceViewMode::class,
            'expires_at' => 'datetime',
            'approval_enabled' => 'boolean',
            'design' => 'array',
            'settings' => 'array',
            'seo' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            // Delivery-log milestones: written once, never recomputed.
            'first_opened_at' => 'datetime',
            'first_downloaded_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $space): void {
            $space->ulid ??= (string) str()->ulid();
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
     * @return HasMany<SpaceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SpaceItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<SpaceEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SpaceEvent::class);
    }

    /**
     * @return HasMany<SpaceDailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(SpaceDailyStat::class);
    }

    public function isPublished(): bool
    {
        return $this->status === SpaceStatus::Published;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Slug from the title, unique per user — dedup includes trashed rows,
     * because a deleted Space's link must never be reissued.
     */
    public static function generateSlug(User $user, string $title): string
    {
        $base = Str::limit(Str::slug($title), 72, '') ?: 'space';
        $slug = $base;
        $n = 1;

        while (
            static::withTrashed()
                ->where('user_id', $user->id)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    /**
     * An empty design document: one untitled section, nothing in it.
     *
     * @return array<string, mixed>
     */
    public static function emptyDesign(): array
    {
        return [
            'fit' => 'cover',
            'align' => 'Center',
            'labels' => ['name' => true, 'tags' => true],
            'sections' => [['key' => 's1', 'blocks' => []]],
            'texts' => (object) [],
            'items' => (object) [],
        ];
    }

    /**
     * Default settings document.
     *
     * @return array<string, mixed>
     */
    public static function defaultSettings(): array
    {
        return [
            'allow_download' => true,
            'discoverable' => false,
            'approval_mode' => 'per_file',
        ];
    }
}
