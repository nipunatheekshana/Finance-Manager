<?php

namespace App\Enums;

enum AllocationType: string
{
    case Fixed = 'fixed';
    case SalaryPercentage = 'salary_percentage';
    case ExtraPercentage = 'extra_percentage';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
