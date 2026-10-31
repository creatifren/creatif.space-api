<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bill. Only the webhook may mark it paid.
 *
 * @property int $id
 * @property string $ulid
 * @property int $subscription_id
 * @property string $number
 * @property int $amount US cents (IDR for rows from before Creem)
 * @property string $currency
 * @property InvoiceStatus $status
 * @property string|null $creem_checkout_id
 * @property string|null $creem_checkout_url
 * @property string|null $creem_transaction_id
 * @property string|null $payment_method
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $raw_notification
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** How long a hosted checkout is waited on before the bill is stale. */
    public const DUE_HOURS = 24;

    protected $fillable = [
        'subscription_id',
        'number',
        'amount',
        'currency',
        'status',
        'creem_checkout_id',
        'creem_checkout_url',
        'creem_transaction_id',
        'payment_method',
        'due_at',
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
            'status' => InvoiceStatus::class,
            'amount' => 'integer',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'raw_notification' => 'array',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            $invoice->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * A settled invoice never moves again — a second webhook for the same
     * order must find this and stop.
     */
    public function isSettled(): bool
    {
        return in_array(
            $this->status,
            [InvoiceStatus::Paid, InvoiceStatus::Refunded],
            true,
        );
    }

    /**
     * INV-2026-000123 — a running number per year. Called inside the
     * checkout transaction, where the row lock keeps it unique.
     */
    public static function nextNumber(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        $last = static::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
