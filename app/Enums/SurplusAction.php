<?php

namespace App\Enums;

/**
 * What the user chose to do with a finished cycle's leftover money.
 */
enum SurplusAction: string
{
    case Debt = 'debt';
    case Savings = 'savings';
    case CarryForward = 'carry_forward';
    case LeaveInBank = 'leave_in_bank';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
