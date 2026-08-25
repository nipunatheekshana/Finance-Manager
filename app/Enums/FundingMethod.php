<?php

namespace App\Enums;

/**
 * Where a cycle's plan gets the money it allocates.
 *
 * This is the one decision that separates a salaried plan from an irregular
 * one. Everything downstream — weekly budgets, daily limits, overspend
 * handling — works the same whichever is chosen.
 */
enum FundingMethod: string
{
    /** A known salary, the same every cycle. */
    case Fixed = 'fixed';

    /**
     * A steady amount the user pays themselves regardless of what came in.
     * Income collects in a holding pot and the difference becomes runway.
     */
    case Draw = 'draw';

    /** A projection from recent cycles, optionally discounted to be cautious. */
    case Forecast = 'forecast';

    /** Only money actually received. The plan starts at zero and grows. */
    case Actual = 'actual';

    /** A salary plus a draw from self-employed income. */
    case SalaryPlusDraw = 'salary_plus_draw';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'My salary',
            self::Draw => 'A steady amount I pay myself',
            self::Forecast => 'A forecast from recent months',
            self::Actual => 'Only what has actually arrived',
            self::SalaryPlusDraw => 'My salary plus a draw',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Fixed => 'Your plan is built on the same figure every cycle.',
            self::Draw => 'Lumpy income is smoothed into a steady amount to live on.',
            self::Forecast => 'Projected from what you earned recently.',
            self::Actual => 'Nothing can be budgeted until it is in the bank.',
            self::SalaryPlusDraw => 'Your salary, plus a steady draw from your own work.',
        };
    }

    /** Whether the plan needs a holding pot and a runway figure. */
    public function usesHoldingPot(): bool
    {
        return in_array($this, [self::Draw, self::SalaryPlusDraw], true);
    }

    /** Whether the funding figure moves during the cycle as income lands. */
    public function isProgressive(): bool
    {
        return $this === self::Actual;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
