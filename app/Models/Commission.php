<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CommissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One twelfth of what a payment earned, and the date it can be withdrawn.
 *
 * @property int $id
 * @property int $referral_id
 * @property int $invoice_id
 * @property int $amount
 * @property string $tier_percent
 * @property int $installment_no
 * @property CommissionStatus $status
 * @property CarbonImmutable $hold_until
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $created_at
 * @property-read Referral $referral
 * @property-read Invoice $invoice
 */
class Commission extends Model
{
    /** @use HasFactory<CommissionFactory> */
    use HasFactory;

    protected $fillable = [
        'referral_id',
        'invoice_id',
        'amount',
        'tier_percent',
        'installment_no',
        'hold_until',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'installment_no' => 'integer',
            'status' => CommissionStatus::class,
            'hold_until' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Referral, $this>
     */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isHolding(): bool
    {
        return $this->status === CommissionStatus::Holding;
    }
}
