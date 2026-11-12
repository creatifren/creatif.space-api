<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Tester feedback (POST /feedback, MCP send_feedback). Stored, then
 * emailed to the team with reply-to the user.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $kind bug | idea | other
 * @property string $message
 * @property string|null $page
 * @property string $source web | mcp
 * @property bool $internal
 * @property CarbonImmutable|null $created_at
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    public const KINDS = ['bug', 'idea', 'other'];

    protected $fillable = ['user_id', 'kind', 'message', 'page', 'source', 'internal'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['internal' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $feedback): void {
            $feedback->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
