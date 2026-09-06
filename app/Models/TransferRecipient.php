<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address the sender named. Whoever signs in with it finds the transfer
 * under "Received".
 *
 * It grants nothing: the link works for anyone who holds it, with or
 * without an account, so this row is a convenience for people who happen
 * to have one. The address is never verified, which is exactly why it must
 * not be allowed to unlock anything.
 *
 * @property int $id
 * @property int $transfer_id
 * @property string $email
 */
class TransferRecipient extends Model
{
    protected $fillable = ['transfer_id', 'email'];

    /**
     * @return BelongsTo<Transfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }
}
