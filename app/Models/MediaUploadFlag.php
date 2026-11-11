<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INTERNAL: an account that keeps hitting upload caps. Review only.
 *
 * @property int $id
 * @property int $user_id
 * @property int $hits
 * @property int $total_hits
 * @property string|null $last_reason
 * @property CarbonImmutable|null $window_started_at
 * @property CarbonImmutable|null $last_hit_at
 * @property CarbonImmutable|null $flagged_at
 */
class MediaUploadFlag extends Model
{
    protected $fillable = ['user_id'];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'total_hits' => 'integer',
            'window_started_at' => 'datetime',
            'last_hit_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
