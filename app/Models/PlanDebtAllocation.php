<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanDebtAllocation extends Model
{
    protected $fillable = [
        'monthly_plan_id',
        'debt_id',
        'minimum_payment',
        'recommended_payment',
        'planned_amount',
        'paid_amount',
    ];

    protected function casts(): array
    {
        return [
            'minimum_payment' => 'decimal:2',
            'recommended_payment' => 'decimal:2',
            'planned_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
