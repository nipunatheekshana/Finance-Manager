<?php

namespace App\Enums;

enum IncomeSourceKind: string
{
    case Salary = 'salary';
    case Client = 'client';
    case Project = 'project';
    case Business = 'business';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'Salary',
            self::Client => 'Client',
            self::Project => 'Project',
            self::Business => 'Business',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
