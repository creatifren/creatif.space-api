<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A top-up waiting on, or settled by, Midtrans. The Credits land in the
 * ledger once, when the webhook says paid — this row is the receipt.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $credits
 * @property int $amount
 * @property string $status
 * @property string $midtrans_order_id
 * @property string|null $midtrans_snap_token
 * @property string|null $payment_method
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $raw_notification
 * @property CarbonImmutable|null $created_at
 */
class CreditPurchase extends Model
{
    public const ORDER_PREFIX = 'CR-';

    protected $fillable = [
        'user_id', 'credits', 'amount', 'status', 'midtrans_order_id',
        'midtrans_snap_token', 'payment_method', 'paid_at', 'raw_notification',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'raw_notification' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $purchase): void {
            $purchase->ulid ??= (string) Str::ulid();
            $purchase->midtrans_order_id ??= self::ORDER_PREFIX.$purchase->ulid;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSettled(): bool
    {
        return $this->status !== 'pending';
    }
}
