<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder in the file library. Display-only hierarchy: files keep their
 * object key wherever they sit, the folder is just how the owner sees them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 */
class Folder extends Model
{
    protected $fillable = [
        'ulid',
        'user_id',
        'parent_id',
        'name',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $folder): void {
            $folder->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Resolve a folder ulid from API input against its owner. Null in, null
     * out (the root); a ulid that is not this user's is a 404, never a leak.
     */
    public static function ownedBy(User $user, ?string $ulid): ?self
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        return self::query()->where('ulid', $ulid)->where('user_id', $user->id)->firstOrFail();
    }

    /**
     * Breadcrumb, root-first, this folder last.
     *
     * @return list<array{id: string, name: string}>
     */
    public function ancestorsAndSelf(): array
    {
        // ponytail: one query per level. A nested-set or path column when
        // trees get deep.
        $crumbs = [];
        for ($node = $this; $node !== null; $node = $node->parent) {
            array_unshift($crumbs, ['id' => $node->ulid, 'name' => $node->name]);
        }

        return $crumbs;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }
}
