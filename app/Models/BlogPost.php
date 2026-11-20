<?php

namespace App\Models;

use App\Models\Concerns\HasSampleFlag;
use App\Models\Contracts\WorkspaceOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A Blog post (v7: the only long-form type; a press release is a Blog post
 * that needs client approval). Blocks: photo (+ caption), paragraph,
 * heading, quote, list, divider. With needs_approval it goes into an
 * approval link and is published to the Blog once approved. The public
 * Blog shows published posts only (UX review H2).
 *
 * @property int $id
 * @property string $ulid
 * @property int $space_id
 * @property int $user_id
 * @property string $title
 * @property string $slug
 * @property array<int, array<string, mixed>>|null $blocks
 * @property string $status
 * @property bool $needs_approval
 * @property int|null $social_approval_link_id
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable|null $approval_at
 * @property string|null $approval_by
 * @property string|null $approval_comment
 * @property int|null $cover_file_id
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property bool $is_demo sample data (App\Support\SampleData)
 */
class BlogPost extends Model implements WorkspaceOwned
{
    use HasSampleFlag;

    public const STATUSES = ['draft', 'waiting', 'changes_requested', 'approved', 'published'];

    public const BLOCKS = ['photo', 'paragraph', 'heading', 'quote', 'list', 'divider'];

    /** Column defaults, so a fresh model reads like the stored row. */
    protected $attributes = ['status' => 'draft', 'needs_approval' => false];

    protected $fillable = ['space_id', 'user_id', 'social_post_id', 'title', 'slug', 'cover_file_id', 'blocks', 'status', 'needs_approval', 'social_approval_link_id', 'approval_by', 'approval_comment', 'approval_at', 'published_at'];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'needs_approval' => 'boolean',
            'approval_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $post): void {
            $post->ulid ??= (string) Str::ulid();
        });
    }

    public function workspaceUserId(): ?int
    {
        return $this->user_id;
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return BelongsTo<File, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(File::class, 'cover_file_id');
    }

    /** @return BelongsTo<SocialApprovalLink, $this> */
    public function approvalLink(): BelongsTo
    {
        return $this->belongsTo(SocialApprovalLink::class, 'social_approval_link_id');
    }

    /** A unique slug in its Blog for this title. */
    public static function slugFor(Space $space, string $title, ?int $except = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $base = mb_substr($base, 0, 140);
        $slug = $base;
        for ($n = 2; self::query()->where('space_id', $space->id)->where('slug', $slug)->when($except, fn ($q) => $q->whereKeyNot($except))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /**
     * Blocks as the public page and the editor read them: photo blocks carry
     * the file's URL; unknown types are dropped.
     *
     * @return list<array<string, mixed>>
     */
    public function publicBlocks(): array
    {
        $blocks = array_values(array_filter($this->blocks ?? [], fn (array $b) => in_array($b['type'] ?? null, self::BLOCKS, true)));
        $ids = array_values(array_filter(array_map(fn ($b) => $b['type'] === 'photo' ? ($b['file_id'] ?? null) : null, $blocks)));
        $files = $ids === [] ? collect() : File::query()->whereIn('ulid', $ids)->where('user_id', $this->user_id)->get()->keyBy('ulid');

        return array_map(function (array $b) use ($files) {
            if ($b['type'] === 'photo') {
                $f = $files->get($b['file_id'] ?? '');
                $b['url'] = $f?->url();
                $b['width'] = $f?->width;
                $b['height'] = $f?->height;
            }

            return $b;
        }, $blocks);
    }
}
