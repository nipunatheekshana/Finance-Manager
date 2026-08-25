<?php

namespace App\Enums;

enum DebtType: string
{
    case CreditCard = 'credit_card';
    case Installment = 'installment';
    case Loan = 'loan';
    case Personal = 'personal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CreditCard => 'Credit Card',
            self::Installment => 'Installment',
            self::Loan => 'Loan',
            self::Personal => 'Personal Debt',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
