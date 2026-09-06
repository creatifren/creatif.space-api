<?php

namespace App\Models;

use App\Enums\ActivityAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the ledger: what I did, to what, when.
 *
 * @property int $id
 * @property int $user_id
 * @property ActivityAction $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $summary
 * @property array<string, mixed>|null $meta
 */
class Activity extends Model
{
    protected $table = 'activity_log';

    /** Only ever written once, so there is nothing for `updated_at` to say. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id', 'summary', 'meta',
    ];

    protected function casts(): array
    {
        return ['action' => ActivityAction::class, 'meta' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record something. Deliberately forgiving: a ledger write must never
     * be the reason an upload fails, so callers do not have to guard it and
     * a bad row is dropped rather than thrown.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function log(
        ?User $user,
        ActivityAction $action,
        string $summary,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $meta = null,
    ): void {
        if ($user === null) {
            return;
        }

        try {
            static::create([
                'user_id' => $user->id,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'summary' => $summary,
                'meta' => $meta,
            ]);
        } catch (\Throwable) {
            /* Losing a history line is a worse outcome than nothing, and a
               better one than losing the upload it describes. */
        }
    }
}
