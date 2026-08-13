<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Carbon\CarbonImmutable;
use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the creator's balance. Append-only — see App\Support\Wallet,
 * which is the only thing that should write these.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property WalletTransactionType $type
 * @property int $amount
 * @property int $balance_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 */
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use HasFactory;

    /** A ledger line is never edited. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->ulid ??= (string) str()->ulid();
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
}
