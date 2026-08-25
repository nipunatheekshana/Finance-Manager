<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFixedExpense extends Model
{
    protected $fillable = [
        'monthly_plan_id',
        'recurring_transaction_id',
        'category_id',
        'payment_method_id',
        'name',
        'amount',
        'actual_amount',
        'occurrences',
        'due_date',
        'status',
        'postponed_to',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'occurrences' => 'integer',
            'due_date' => 'date',
            'postponed_to' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public const STATUSES = ['planned', 'paid', 'skipped', 'postponed'];

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** Skipped bills contribute nothing to the plan's fixed-expense total. */
    public function countsTowardPlan(): bool
    {
        return ! in_array($this->status, ['skipped', 'postponed'], true);
    }

    public function effectiveAmount(): string
    {
        return \App\Support\Money::of($this->actual_amount ?? $this->amount);
    }
}
