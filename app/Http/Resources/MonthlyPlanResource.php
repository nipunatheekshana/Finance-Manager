<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonthlyPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'label' => $this->label(),
            'status' => $this->status->value,
            'expected_income' => Money::of($this->expected_income),
            'actual_income' => $this->actual_income === null ? null : Money::of($this->actual_income),
            'extra_income' => Money::of($this->extra_income),
            'opening_balance' => Money::of($this->opening_balance),
            'carried_forward' => Money::of($this->carried_forward),
            'surplus_amount' => $this->surplus_amount === null ? null : Money::of($this->surplus_amount),
            'surplus_resolved_at' => $this->surplus_resolved_at?->toIso8601String(),
            'total_income' => $this->totalIncome(),
            'fixed_expenses' => Money::of($this->fixed_expenses),
            'debt_payment' => Money::of($this->debt_payment),
            'savings' => Money::of($this->savings),
            'spending_budget' => Money::of($this->spending_budget),
            'buffer' => Money::of($this->buffer),
            'buffer_used' => Money::of($this->buffer_used),
            'buffer_remaining' => $this->bufferRemaining(),
            'cycle_start_date' => $this->cycle_start_date->toDateString(),
            'cycle_end_date' => $this->cycle_end_date->toDateString(),
            'allow_deficit' => $this->allow_deficit,
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'weekly_budgets' => WeeklyBudgetResource::collection($this->whenLoaded('weeklyBudgets')),
            'fixed_expense_items' => PlanFixedExpenseResource::collection($this->whenLoaded('fixedExpenses')),
            'debt_allocations' => PlanDebtAllocationResource::collection($this->whenLoaded('debtAllocations')),
            'savings_allocations' => PlanSavingsAllocationResource::collection($this->whenLoaded('savingsAllocations')),
        ];
    }
}
