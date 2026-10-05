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

    /* ── Credits wallet ── */
    case CreditsTopUp = 'credits_top_up';
    /** The plan's monthly Credits, or Free's thirty at sign-up. */
    case CreditsGrant = 'credits_grant';
    /** Three per destination, taken when the post is handed to the provider. */
    case CreditsSend = 'credits_send';
    /** A destination that never went out — failed or cancelled — comes back. */
    case CreditsSendReturn = 'credits_send_return';
    /** Storage blocks, on the first of the month or when one is added. */
    case CreditsRent = 'credits_rent';
}
