<?php

namespace App\Enums;

enum AmountType: string
{
    case Fixed = 'fixed';
    case Estimated = 'estimated';
    case Range = 'range';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
