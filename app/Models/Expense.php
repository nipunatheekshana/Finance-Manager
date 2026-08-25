<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'payment_method_id',
        'monthly_plan_id',
        'weekly_budget_id',
        'debt_id',
        'recurring_transaction_id',
        'amount',
        'expense_date',
        'description',
        'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function monthlyPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyPlan::class);
    }

    public function weeklyBudget(): BelongsTo
    {
        return $this->belongsTo(WeeklyBudget::class);
    }

    /** The credit card / debt this spending was charged to, when applicable. */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class);
    }

    public function scopeBetween(Builder $query, mixed $start, mixed $end): Builder
    {
        return $query->whereBetween('expense_date', [$start, $end]);
    }

    public function scopeOnDate(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('expense_date', $date);
    }

    /**
     * Day-to-day spending only.
     *
     * Payments logged against a recurring bill are excluded: fixed bills are
     * budgeted separately in the monthly plan, so counting them here as well
     * would charge the user twice for the same money.
     */
    public function scopeDiscretionary(Builder $query): Builder
    {
        return $query->whereNull('recurring_transaction_id');
    }
}
