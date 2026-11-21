<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone the Android app registered for push (FCM). The token is the
 * phone's address at Firebase; FCM answering UNREGISTERED deletes it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $platform
 * @property string|null $app_version
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class DeviceToken extends Model
{
    protected $fillable = ['token', 'platform', 'app_version', 'last_seen_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
