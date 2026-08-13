<?php

namespace App\Models;

use App\Enums\TeamMemberStatus;
use App\Enums\TeamRole;
use Carbon\CarbonImmutable;
use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * One seat on somebody's Team plan.
 *
 * @property int $id
 * @property string $ulid
 * @property int $owner_id
 * @property int|null $member_id
 * @property string $email
 * @property TeamRole $role
 * @property TeamMemberStatus $status
 * @property CarbonImmutable|null $invited_at
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $created_at
 * @property-read User $owner
 * @property-read User|null $member
 */
class TeamMember extends Model
{
    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory, Notifiable;

    /**
     * The invitation goes to the address that was typed, which may not
     * belong to any account yet — that is the whole point of inviting by
     * email rather than by user.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    protected $fillable = [
        'owner_id',
        'member_id',
        'email',
        'role',
        'invited_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'status' => TeamMemberStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $member): void {
            $member->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * Invited or active — both occupy a seat, because a seat is reserved
     * the moment it is offered.
     */
    public function occupiesSeat(): bool
    {
        return $this->status !== TeamMemberStatus::Removed;
    }
}
