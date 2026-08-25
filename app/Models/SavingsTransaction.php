<?php

namespace App\Models;

use App\Enums\SavingsTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'savings_goal_id',
        'monthly_plan_id',
        'type',
        'amount',
        'transaction_date',
        'description',
        'related_goal_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => SavingsTransactionType::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class);
    }

    public function relatedGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'related_goal_id');
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }
}
