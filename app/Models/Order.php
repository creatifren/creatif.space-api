<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchase, with the fee it cost frozen into it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property int $client_id
 * @property int|null $offer_id
 * @property int|null $space_id
 * @property-read Offer|null $offer
 * @property-read Space|null $space
 * @property int $amount
 * @property string $fee_percent
 * @property int $fee_amount
 * @property int $net_amount
 * @property OrderStatus $status
 * @property string|null $midtrans_order_id
 * @property string|null $payment_method
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $raw_notification
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** How long a Snap checkout stays payable. */
    public const DUE_HOURS = 24;

    protected $fillable = [
        'creator_id',
        'client_id',
        'offer_id',
        'space_id',
        'amount',
        'fee_percent',
        'fee_amount',
        'net_amount',
        'status',
        'midtrans_order_id',
        'payment_method',
        'paid_at',
        'raw_notification',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'paid_at' => 'datetime',
            'raw_notification' => 'array',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * withTrashed so a retired offer can still name what was sold. The FK
     * is nullable — a straight tip has no offer — so reads go through
     * offerTitle() rather than touching ->offer->title directly.
     *
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class)->withTrashed();
    }

    /**
     * What was sold. "Tip" when there was no offer at all.
     */
    public function offerTitle(): string
    {
        return $this->offer_id === null ? 'Tip' : $this->offer->title;
    }

    /**
     * Where the buyer collects the goods — the offer's `details.source_ref`
     * (a file link, Drive link, or a private Space address), normalised to
     * an absolute URL. Null for tips, offers without one, and junk values:
     * a delivery link that is not a URL is worse than none.
     */
    public function deliveryUrl(): ?string
    {
        $ref = $this->offer?->details['source_ref'] ?? null;

        if (! is_string($ref) || $ref === '') {
            return null;
        }

        // A pasted Space address usually arrives without a scheme.
        if (! preg_match('/^https?:\/\//i', $ref)) {
            $ref = 'https://'.$ref;
        }

        return filter_var($ref, FILTER_VALIDATE_URL) ? $ref : null;
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * A settled order never moves again — the second webhook for it must
     * find this and stop.
     */
    public function isSettled(): bool
    {
        return in_array(
            $this->status,
            [OrderStatus::Paid, OrderStatus::Refunded],
            true,
        );
    }

    /**
     * Split an amount by a fee percentage. Integer Rupiah throughout: the
     * fee rounds, and the creator gets the remainder, so the two halves
     * always add back to exactly what the buyer paid.
     *
     * @return array{fee: int, net: int}
     */
    public static function split(int $amount, string $feePercent): array
    {
        $fee = (int) round($amount * (float) $feePercent / 100);

        return ['fee' => $fee, 'net' => $amount - $fee];
    }
}
