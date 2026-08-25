<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialProfile extends Model
{
    protected $fillable = [
        'user_id',
        'base_salary',
        'salary_day',
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
            'base_salary' => 'decimal:2',
            'default_buffer' => 'decimal:2',
            'salary_day' => 'integer',
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
}
