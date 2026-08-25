<?php

namespace App\Http\Requests;

/**
 * Shared validation for monetary input.
 *
 * Amounts arrive as numbers or numeric strings and are stored as
 * DECIMAL(15,2), so anything with more than two decimal places or beyond the
 * column's range is rejected rather than silently rounded.
 */
trait MoneyRules
{
    /** @return list<string> */
    protected function moneyRules(bool $required = true, float $min = 0): array
    {
        return [
            $required ? 'required' : 'nullable',
            'numeric',
            'min:'.$min,
            'max:9999999999999.99',
            'decimal:0,2',
        ];
    }

    /** @return list<string> */
    protected function positiveMoneyRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'numeric',
            'gt:0',
            'max:9999999999999.99',
            'decimal:0,2',
        ];
    }
}
