<?php

namespace App\Enums;

enum IncomeCadence: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Daily = 'daily';
    case PerProject = 'per_project';
    case Irregular = 'irregular';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
