<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A note on a post — from the editor, or from a client on an approval link
 * who gave only a name. Append-only: resolving hides it, nothing deletes it.
 *
 * Roles are printed, not inferred: EDITOR wrote it in the app, APPROVER is a
 * client's decision note, COMMENTER is a client's opinion.
 *
 * @property int $id
 * @property string $ulid
 * @property int $social_post_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $author_name
 * @property string $role
 * @property string $body
 * @property string|null $suggestion
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 */
class SocialPostComment extends Model
{
    protected $fillable = [
        'social_post_id', 'parent_id', 'user_id', 'author_name', 'role', 'body', 'suggestion', 'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $comment): void {
            $comment->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<SocialPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /**
     * @return HasMany<SocialPostComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    /**
     * The shape both the dashboard and the approval link read.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->ulid,
            'author' => $this->author_name,
            'role' => $this->role,
            'body' => $this->body,
            'suggestion' => $this->suggestion,
            'resolved' => $this->resolved_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'replies' => $this->relationLoaded('replies')
                ? $this->replies->map(fn (self $r) => $r->toPublicArray())->all()
                : [],
        ];
    }
}
