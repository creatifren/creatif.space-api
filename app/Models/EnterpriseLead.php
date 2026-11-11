<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An Enterprise "Contact sales" request. Stored and emailed to sales.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string $company
 * @property string $team_size
 * @property string $message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EnterpriseLead extends Model
{
    protected $fillable = ['user_id', 'name', 'email', 'company', 'team_size', 'message'];

    protected static function booted(): void
    {
        static::creating(function (self $lead): void {
            $lead->ulid ??= (string) Str::ulid();
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
