<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's follower count on one day, as the relay reported it. The
 * relay only knows "now", so this table is the only source of a
 * period-start number in Insight reports. Written by RefreshFollowerCount.
 *
 * @property int $id
 * @property int $social_account_id
 * @property CarbonImmutable $on
 * @property int $followers
 */
class FollowerSnapshot extends Model
{
    protected $table = 'social_account_follower_snapshots';

    protected $fillable = ['social_account_id', 'on', 'followers'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on' => 'immutable_date',
            'followers' => 'integer',
        ];
    }

    /** Today's count for the account; a second refresh the same day overwrites. */
    public static function record(SocialAccount $account, int $followers): void
    {
        $today = now()->toDateString();
        $row = static::query()
            ->where('social_account_id', $account->id)
            ->whereDate('on', $today)
            ->first();

        if ($row !== null) {
            $row->update(['followers' => $followers]);

            return;
        }

        static::query()->create(['social_account_id' => $account->id, 'on' => $today, 'followers' => $followers]);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
