<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetCategory extends Model
{
    protected $fillable = [
        'monthly_plan_id',
        'category_id',
        'is_allowance',
        'budget_amount',
        'spent_amount',
    ];

    protected function casts(): array
    {
        return [
            'is_allowance' => 'boolean',
            'budget_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
        ];
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
