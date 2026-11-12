<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_kind
 * @property string $token_id
 * @property string|null $client_id
 * @property string|null $client_name
 * @property string|null $tool
 * @property string|null $route
 * @property string $method
 * @property int $status
 * @property int|null $duration_ms
 * @property string|null $ip
 * @property CarbonImmutable|null $created_at
 */
class McpAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
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
