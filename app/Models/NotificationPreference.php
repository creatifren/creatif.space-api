<?php

namespace App\Models;

use App\Enums\NotificationType;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One switch pair per notification type. A row only exists once the user
 * has touched the setting — absence means both channels are on.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationType $type
 * @property bool $email_enabled
 * @property bool $bell_enabled
 */
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'email_enabled',
        'bell_enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'email_enabled' => 'boolean',
            'bell_enabled' => 'boolean',
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
