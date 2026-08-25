<?php

namespace App\Models;

use App\Enums\CycleAnchor;
use App\Enums\FundingMethod;
use App\Enums\IncomeMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProfile extends Model
{
    protected $fillable = [
        'user_id',
        'income_mode',
        'cycle_anchor',
        'funding_method',
        'base_salary',
        'target_draw',
        'forecast_months',
        'forecast_factor',
        'cycle_start_day',
        'has_extra_income',
        'default_buffer',
        'extra_debt_percentage',
        'extra_savings_percentage',
        'extra_spending_percentage',
        'theme',
        'notification_settings',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'income_mode' => IncomeMode::class,
            'cycle_anchor' => CycleAnchor::class,
            'funding_method' => FundingMethod::class,
            'base_salary' => 'decimal:2',
            'target_draw' => 'decimal:2',
            'default_buffer' => 'decimal:2',
            'forecast_months' => 'integer',
            'forecast_factor' => 'integer',
            'cycle_start_day' => 'integer',
            'has_extra_income' => 'boolean',
            'extra_debt_percentage' => 'integer',
            'extra_savings_percentage' => 'integer',
            'extra_spending_percentage' => 'integer',
            'notification_settings' => 'array',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /** Notification types default to on until the user turns one off. */
    public const NOTIFICATION_TYPES = [
        'salary_day',
        'upcoming_bills',
        'debt_payments',
        'budget_warnings',
        'budget_exceeded',
        'savings_goals',
        'weekly_review',
        'cycle_surplus',
        'income_health',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wantsNotification(string $type): bool
    {
        return (bool) ($this->notification_settings[$type] ?? true);
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /** Whether a fixed salary is part of this account's income. */
    public function hasSalary(): bool
    {
        return $this->income_mode->hasSalary();
    }

    /** Whether income arrives irregularly and needs the ledger. */
    public function hasIrregularIncome(): bool
    {
        return $this->income_mode->hasIrregularIncome();
    }

    /**
     * Apply a mode's presets. Explicit overrides win, so a user who has tuned
     * their anchor or funding method does not silently lose it.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function applyIncomeMode(IncomeMode $mode, array $overrides = []): void
    {
        $this->forceFill([
            'income_mode' => $mode->value,
            'cycle_anchor' => $overrides['cycle_anchor'] ?? $mode->defaultCycleAnchor()->value,
            'funding_method' => $overrides['funding_method'] ?? $mode->defaultFundingMethod()->value,
        ]);
    }
}
