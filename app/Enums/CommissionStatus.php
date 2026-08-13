<?php

namespace App\Enums;

/**
 * Money that has been earned but not yet handed over. A refund inside the
 * holding window makes a commission `reversed` rather than deleting it —
 * the earning happened, and then it didn't stand.
 */
enum CommissionStatus: string
{
    case Holding = 'holding';
    case Released = 'released';
    case Reversed = 'reversed';
}
