<?php

namespace App\Models;

use App\Enums\ApprovalCancelReason;
use App\Enums\ApprovalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One client's decision on one file, with the date it was given.
 *
 * @property int $id
 * @property string $ulid
 * @property int $space_item_id
 * @property int $client_id
 * @property ApprovalStatus $status
 * @property CarbonImmutable|null $approved_at
 * @property ApprovalCancelReason|null $cancelled_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    protected $fillable = [
        'space_item_id',
        'client_id',
        'status',
        'approved_at',
        'cancelled_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'cancelled_reason' => ApprovalCancelReason::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            $approval->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<SpaceItem, $this>
     */
    public function spaceItem(): BelongsTo
    {
        return $this->belongsTo(SpaceItem::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<ApprovalNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ApprovalNote::class)->orderBy('id');
    }

    /**
     * Only a standing approval can be voided — a revision request was never
     * a sign-off, and a cancelled one is already gone.
     */
    public function isVoidable(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    /**
     * Take the sign-off back, recording why. approved_at stays: it is the
     * history of what happened, not a claim about the file today.
     */
    public function void(ApprovalCancelReason $reason): void
    {
        $this->forceFill([
            'status' => ApprovalStatus::Cancelled,
            'cancelled_reason' => $reason,
        ])->save();
    }
}
