<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    /** Finalised before its cycle starts; becomes active on its first day. */
    case Scheduled = 'scheduled';
    case Completed = 'completed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
