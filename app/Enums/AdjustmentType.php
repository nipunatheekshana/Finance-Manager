<?php

namespace App\Enums;

enum AdjustmentType: string
{
    case NextWeek = 'next_week';
    case Buffer = 'buffer';
    case Category = 'category';
    case Ignore = 'ignore';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
