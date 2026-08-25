<?php

namespace App\Enums;

/**
 * Where a piece of income has got to. Only received income is money you have.
 */
enum IncomeStatus: string
{
    /** Anticipated but not yet billed. */
    case Expected = 'expected';

    /** Billed and awaiting payment. */
    case Invoiced = 'invoiced';

    /** In the bank. */
    case Received = 'received';

    public function isReceived(): bool
    {
        return $this === self::Received;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
