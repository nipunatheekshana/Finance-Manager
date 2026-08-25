<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
