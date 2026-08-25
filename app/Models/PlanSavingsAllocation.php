<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanSavingsAllocation extends Model
{
    protected $fillable = [
        'monthly_plan_id',
        'savings_goal_id',
        'recommended_amount',
        'planned_amount',
        'saved_amount',
    ];

    protected function casts(): array
    {
        return [
            'recommended_amount' => 'decimal:2',
            'planned_amount' => 'decimal:2',
            'saved_amount' => 'decimal:2',
        ];
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class);
    }
}
