<?php

namespace App\Models;

use App\Enums\AdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAdjustment extends Model
{
    protected $fillable = [
        'user_id',
        'monthly_plan_id',
        'weekly_budget_id',
        'source_weekly_budget_id',
        'category_id',
        'type',
        'amount',
        'original_amount',
        'adjusted_amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    /** The week that received the adjustment. */
    public function weeklyBudget(): BelongsTo
    {
        return $this->belongsTo(WeeklyBudget::class);
    }

    /** The overspent week that triggered it. */
    public function sourceWeeklyBudget(): BelongsTo
    {
        return $this->belongsTo(WeeklyBudget::class, 'source_weekly_budget_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
