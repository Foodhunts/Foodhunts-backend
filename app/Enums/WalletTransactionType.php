<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case ReferralBonus = 'referral_bonus';
}
