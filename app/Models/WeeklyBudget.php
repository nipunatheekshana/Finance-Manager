<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyBudget extends Model
{
    protected $fillable = [
        'monthly_plan_id',
        'week_number',
        'start_date',
        'end_date',
        'budget_amount',
        'adjusted_amount',
        'spent_amount',
    ];

    protected function casts(): array
    {
        return [
            'week_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'budget_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
        ];
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * The budget actually in force: the adjusted figure when the user approved
     * one, otherwise the originally planned amount.
     */
    public function effectiveBudget(): string
    {
        return Money::of($this->adjusted_amount ?? $this->budget_amount);
    }

    public function containsDate(\DateTimeInterface $date): bool
    {
        $day = \Carbon\Carbon::instance($date)->startOfDay();

        return $day->betweenIncluded($this->start_date->startOfDay(), $this->end_date->startOfDay());
    }
}
