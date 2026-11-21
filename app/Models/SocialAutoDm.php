<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Auto DM a post's rule sent (or tried) to one commenter (v10.5).
 *
 * @property int $id
 * @property int $social_post_id
 * @property int|null $social_comment_id
 * @property int $user_id
 * @property string $commenter author id (else username) — one DM per person per post
 * @property string $status sent | failed | no_credits
 * @property int $credits
 * @property string|null $error
 */
class SocialAutoDm extends Model
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const NO_CREDITS = 'no_credits';

    protected $fillable = ['social_post_id', 'social_comment_id', 'user_id', 'commenter', 'status', 'credits', 'error'];

    /** @return BelongsTo<SocialPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}
