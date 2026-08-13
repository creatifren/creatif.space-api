<?php

namespace App\Enums;

/**
 * Every way the balance moves. Affiliate commission (Fase 6) is kept apart
 * from sales because the product promises a separate balance.
 */
enum WalletTransactionType: string
{
    case SaleCredit = 'sale_credit';
    case RefundDebit = 'refund_debit';
    case WithdrawalDebit = 'withdrawal_debit';
    case WithdrawalFailedCredit = 'withdrawal_failed_credit';
    case CommissionCredit = 'commission_credit';
    case Adjustment = 'adjustment';
}
