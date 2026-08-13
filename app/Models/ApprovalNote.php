<?php

namespace App\Models;

use App\Enums\NoteAuthor;
use Carbon\CarbonImmutable;
use Database\Factories\ApprovalNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on an approval: the client's, or the owner's one reply to it.
 *
 * @property int $id
 * @property int $approval_id
 * @property NoteAuthor $author_type
 * @property string $body
 * @property list<string>|null $chips
 * @property CarbonImmutable|null $created_at
 */
class ApprovalNote extends Model
{
    /** @use HasFactory<ApprovalNoteFactory> */
    use HasFactory;

    /** Append-only: a note that was sent is never edited. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'author_type',
        'body',
        'chips',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_type' => NoteAuthor::class,
            'chips' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Approval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
