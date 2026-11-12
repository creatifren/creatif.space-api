<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision on an approval link — the audit trail. Append-only: a
 * take-back is its own row, nothing is ever edited.
 *
 * @property int $id
 * @property int $social_approval_link_id
 * @property int $social_post_id
 * @property string $decision approved | changes_requested | retracted
 * @property int|null $approver_user_id
 * @property string|null $approver_email
 * @property string|null $client_name
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $source web | mcp
 * @property string|null $comment the approver's reason, with "request changes"
 * @property CarbonImmutable|null $created_at
 */
class SocialApprovalDecision extends Model
{
    public const UPDATED_AT = null;

    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const RETRACTED = 'retracted';

    protected $fillable = [
        'social_approval_link_id', 'social_post_id', 'decision',
        'approver_user_id', 'approver_email', 'client_name', 'ip', 'user_agent', 'source', 'comment',
    ];

    /**
     * @return BelongsTo<SocialApprovalLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(SocialApprovalLink::class, 'social_approval_link_id');
    }

    /**
     * @return BelongsTo<SocialPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
