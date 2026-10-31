<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A top-up waiting on, or settled by, Creem. The Credits land in the
 * ledger once, when the webhook says paid — this row is the receipt.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int $credits
 * @property int $units Dollars bought — Creem's $1 Credits product, this many times
 * @property int $amount US cents
 * @property string $currency
 * @property string $status
 * @property string|null $creem_checkout_id
 * @property string|null $payment_method
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $raw_notification
 * @property CarbonImmutable|null $created_at
 */
class CreditPurchase extends Model
{
    protected $fillable = [
        'user_id', 'credits', 'units', 'amount', 'currency', 'status',
        'creem_checkout_id', 'payment_method', 'paid_at', 'raw_notification',
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
