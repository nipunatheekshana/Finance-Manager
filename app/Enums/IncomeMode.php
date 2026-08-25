<?php

namespace App\Enums;

/**
 * How the user earns. This is a preset: it picks sensible defaults for the
 * cycle anchor and the funding method, both of which stay independently
 * changeable afterwards.
 */
enum IncomeMode: string
{
    case Salaried = 'salaried';
    case SelfEmployed = 'self_employed';
    case Business = 'business';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Salaried => 'Employed',
            self::SelfEmployed => 'Freelance or project work',
            self::Business => 'Business owner',
            self::Hybrid => 'Both employed and self-employed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Salaried => 'A regular salary on the same day each month.',
            self::SelfEmployed => 'Income per client, project or invoice.',
            self::Business => 'Takings that vary day to day.',
            self::Hybrid => 'A salary plus income from your own work.',
        };
    }

    public function defaultCycleAnchor(): CycleAnchor
    {
        return match ($this) {
            self::Salaried, self::Hybrid => CycleAnchor::PayDay,
            self::SelfEmployed, self::Business => CycleAnchor::CalendarMonth,
        };
    }

    public function defaultFundingMethod(): FundingMethod
    {
        return match ($this) {
            self::Salaried => FundingMethod::Fixed,
            self::SelfEmployed => FundingMethod::Draw,
            self::Business => FundingMethod::Forecast,
            self::Hybrid => FundingMethod::SalaryPlusDraw,
        };
    }

    /** Whether a fixed salary figure is part of this mode's income. */
    public function hasSalary(): bool
    {
        return in_array($this, [self::Salaried, self::Hybrid], true);
    }

    /** Whether income arrives irregularly and needs a ledger to track. */
    public function hasIrregularIncome(): bool
    {
        return in_array($this, [self::SelfEmployed, self::Business, self::Hybrid], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
