<?php

namespace App\Enums;

enum AdjustmentType: string
{
    case NextWeek = 'next_week';
    case Buffer = 'buffer';
    case Category = 'category';
    case Ignore = 'ignore';

    /** A finished week's leftover, handed to the week after it. */
    case CarryForward = 'carry_forward';

    /** A finished week's leftover, put into a savings goal now. */
    case Savings = 'savings';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
