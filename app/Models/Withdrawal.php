<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\WithdrawalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money on its way to a bank account.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $wallet
 * @property int $amount
 * @property string $bank_code
 * @property string $account_number
 * @property string $account_name
 * @property WithdrawalStatus $status
 * @property string|null $midtrans_payout_id
 * @property CarbonImmutable|null $processed_at
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Withdrawal extends Model
{
    /** @use HasFactory<WithdrawalFactory> */
    use HasFactory;

    /** The product's promise on the pricing page. */
    public const MINIMUM = 50_000;

    protected $fillable = [
        'wallet',
        'amount',
        'bank_code',
        'account_number',
        'account_name',
        'status',
        'midtrans_payout_id',
        'processed_at',
        'failure_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'amount' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $withdrawal): void {
            $withdrawal->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Still in flight — the money is already out of the balance, but has
     * not landed anywhere yet.
     */
    public function isOpen(): bool
    {
        return in_array(
            $this->status,
            [WithdrawalStatus::Pending, WithdrawalStatus::Processing],
            true,
        );
    }

    /** The last four digits, which is all a confirmation screen should show. */
    public function maskedAccount(): string
    {
        return '••••'.substr($this->account_number, -4);
    }
}
