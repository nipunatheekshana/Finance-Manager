<?php

namespace App\Models;

use App\Enums\PlanStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonthlyPlan extends Model
{
    protected $fillable = [
        'user_id',
        'month',
        'year',
        'expected_income',
        'actual_income',
        'extra_income',
        'opening_balance',
        'fixed_expenses',
        'debt_payment',
        'savings',
        'spending_budget',
        'buffer',
        'buffer_used',
        'carried_forward',
        'surplus_amount',
        'cycle_start_date',
        'cycle_end_date',
        'status',
        'allow_deficit',
        'finalized_at',
        'completed_at',
        'reopened_at',
        'surplus_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'year' => 'integer',
            'expected_income' => 'decimal:2',
            'actual_income' => 'decimal:2',
            'extra_income' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'fixed_expenses' => 'decimal:2',
            'debt_payment' => 'decimal:2',
            'savings' => 'decimal:2',
            'spending_budget' => 'decimal:2',
            'buffer' => 'decimal:2',
            'buffer_used' => 'decimal:2',
            'carried_forward' => 'decimal:2',
            'surplus_amount' => 'decimal:2',
            'cycle_start_date' => 'date',
            'cycle_end_date' => 'date',
            'status' => PlanStatus::class,
            'allow_deficit' => 'boolean',
            'finalized_at' => 'datetime',
            'completed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'surplus_resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function weeklyBudgets(): HasMany
    {
        return $this->hasMany(WeeklyBudget::class)->orderBy('week_number');
    }

    public function budgetCategories(): HasMany
    {
        return $this->hasMany(BudgetCategory::class);
    }

    public function fixedExpenses(): HasMany
    {
        return $this->hasMany(PlanFixedExpense::class);
    }

    public function debtAllocations(): HasMany
    {
        return $this->hasMany(PlanDebtAllocation::class);
    }

    public function savingsAllocations(): HasMany
    {
        return $this->hasMany(PlanSavingsAllocation::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function debtPayments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    public function savingsTransactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function incomeTransactions(): HasMany
    {
        return $this->hasMany(IncomeTransaction::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BudgetAdjustment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PlanStatus::Active->value);
    }

    /**
     * The money this plan has to work with: the salary it is built on plus
     * anything carried forward from the previous cycle.
     */
    public function totalIncome(): string
    {
        return Money::add(
            $this->actual_income ?? $this->expected_income,
            $this->opening_balance,
        );
    }

    /** Just the salary, without any carry-in. */
    public function salaryIncome(): string
    {
        return Money::of($this->actual_income ?? $this->expected_income);
    }

    public function isDraft(): bool
    {
        return $this->status === PlanStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === PlanStatus::Active;
    }

    public function isCompleted(): bool
    {
        return $this->status === PlanStatus::Completed;
    }

    public function bufferRemaining(): string
    {
        return Money::floorAtZero(Money::sub($this->buffer, $this->buffer_used));
    }

    public function label(): string
    {
        return \Carbon\Carbon::create($this->year, $this->month, 1)->format('F Y');
    }
}
