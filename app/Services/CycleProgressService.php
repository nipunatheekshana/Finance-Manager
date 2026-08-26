<?php

namespace App\Services;

use App\Models\MonthlyPlan;
use App\Models\PlanDebtAllocation;
use App\Models\PlanFixedExpense;
use App\Models\PlanSavingsAllocation;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One board for the whole cycle: what the plan asked for, what has actually
 * happened, and how far through the cycle that leaves you.
 *
 * Every figure here is already produced somewhere else — the dashboard, the
 * cash-flow screen, the planner. What was missing was a single place that puts
 * income, bills, allowances, debt, savings, spending and buffer side by side
 * and says, for each of them, whether it is done.
 */
class CycleProgressService
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly BudgetCycleService $cycles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forPlan(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $plan->loadMissing([
            'fixedExpenses',
            'debtAllocations.debt',
            'savingsAllocations.savingsGoal',
            'weeklyBudgets',
            'budgetCategories',
        ]);

        $income = $this->income($plan);
        $bills = $this->bills($plan);
        $debts = $this->debts($plan);
        $savings = $this->savings($plan);

        return [
            'plan' => $this->planHeader($plan, $today),
            'overall' => $this->overall($plan, $today, $bills, $debts, $savings),
            'income' => $income,
            'bills' => $bills,
            'allowances' => $this->allowances($plan, $today),
            'debts' => $debts,
            'savings' => $savings,
            'spending' => $this->spending($plan, $today),
            'buffer' => $this->buffer($plan),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planHeader(MonthlyPlan $plan, CarbonImmutable $today): array
    {
        $start = CarbonImmutable::instance($plan->cycle_start_date)->startOfDay();
        $end = CarbonImmutable::instance($plan->cycle_end_date)->startOfDay();

        $total = (int) $start->diffInDays($end) + 1;

        // Clamped: a finished cycle is 100% elapsed, never more.
        $elapsed = max(0, min($total, (int) $start->diffInDays($today) + 1));

        return [
            'id' => $plan->id,
            'label' => $plan->label(),
            'status' => $plan->status->value,
            'cycle_start' => $start->toDateString(),
            'cycle_end' => $end->toDateString(),
            'days_total' => $total,
            'days_elapsed' => $elapsed,
            'days_remaining' => $this->cycles->remainingDays($today, $plan->cycle_end_date),
            'elapsed_percentage' => round($elapsed / max(1, $total) * 100, 1),
            'is_current' => $today->betweenIncluded($start, $end),
        ];
    }

    /**
     * Everything the plan committed to, against everything actually settled.
     *
     * Day-to-day spending is deliberately left out: it is not a commitment to
     * discharge, and counting it would make an underspent cycle look behind.
     *
     * @return array<string, mixed>
     */
    private function overall(
        MonthlyPlan $plan,
        CarbonImmutable $today,
        array $bills,
        array $debts,
        array $savings,
    ): array {
        $committed = Money::add($bills['planned'], $debts['planned'], $savings['planned']);
        $settled = Money::add($bills['settled'], $debts['settled'], $savings['settled']);

        $percentage = Money::percentage($settled, $committed);
        $elapsed = $this->planHeader($plan, $today)['elapsed_percentage'];

        return [
            'committed' => $committed,
            'settled' => $settled,
            'outstanding' => Money::floorAtZero(Money::sub($committed, $settled)),
            'percentage' => $percentage,
            // Behind only counts while the cycle is running: everything left
            // at the end is simply outstanding, not "behind pace".
            'on_track' => $percentage >= $elapsed - 5,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function income(MonthlyPlan $plan): array
    {
        $expected = Money::of($plan->expected_income);
        $received = $plan->actual_income === null ? '0.00' : Money::of($plan->actual_income);

        return [
            'expected' => $expected,
            'received' => $received,
            'extra' => Money::of($plan->extra_income),
            'opening_balance' => Money::of($plan->opening_balance),
            'total' => $plan->totalIncome(),
            'shortfall' => Money::floorAtZero(Money::sub($expected, $received)),
            'is_recorded' => $plan->actual_income !== null,
            'percentage' => Money::percentage($received, $expected),
            'status' => $plan->actual_income === null
                ? 'pending'
                : (Money::lt($received, $expected) ? 'partial' : 'done'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bills(MonthlyPlan $plan): array
    {
        $items = $plan->fixedExpenses
            ->map(fn (PlanFixedExpense $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'amount' => $row->effectiveAmount(),
                'planned_amount' => Money::of($row->amount),
                'due_date' => ($row->postponed_to ?? $row->due_date)?->toDateString(),
                'paid_at' => $row->paid_at?->toDateString(),
                'status' => $row->status,
                // Skipped and postponed bills are out of this cycle's total,
                // so they are not owed and not overdue.
                'counts' => $row->countsTowardPlan(),
            ])
            ->sortBy(fn (array $row) => $row['due_date'] ?? '9999')
            ->values()
            ->all();

        $counted = array_filter($items, fn (array $row) => $row['counts']);
        $paid = array_filter($counted, fn (array $row) => $row['status'] === 'paid');

        $planned = Money::sum(array_column($counted, 'amount'));
        $settled = Money::sum(array_column($paid, 'amount'));

        return [
            'items' => $items,
            'planned' => $planned,
            'settled' => $settled,
            'outstanding' => Money::floorAtZero(Money::sub($planned, $settled)),
            'count' => count($counted),
            'settled_count' => count($paid),
            'percentage' => Money::percentage($settled, $planned),
            'status' => $this->progressStatus($settled, $planned),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allowances(MonthlyPlan $plan, CarbonImmutable $today): array
    {
        $items = $this->budgets->allowanceSummaries($plan, $today);

        $allocated = Money::sum(array_column($items, 'allocated'));
        $spent = Money::sum(array_column($items, 'spent'));

        return [
            'items' => $items,
            'allocated' => $allocated,
            'spent' => $spent,
            'remaining' => Money::floorAtZero(Money::sub($allocated, $spent)),
            'percentage' => Money::percentage($spent, $allocated),
            'over_count' => count(array_filter($items, fn (array $row) => $row['status'] === 'over')),
            // An allowance is not a task to finish: spending all of it is
            // neither good nor bad on its own, only the pace is.
            'ahead_of_pace_count' => count(array_filter($items, fn (array $row) => $row['ahead_of_pace'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function debts(MonthlyPlan $plan): array
    {
        $items = $plan->debtAllocations
            ->map(function (PlanDebtAllocation $allocation) {
                $planned = Money::of($allocation->planned_amount);
                $paid = Money::of($allocation->paid_amount);

                return [
                    'id' => $allocation->id,
                    'debt_id' => $allocation->debt_id,
                    'name' => $allocation->debt->name,
                    'planned' => $planned,
                    'paid' => $paid,
                    'outstanding' => Money::floorAtZero(Money::sub($planned, $paid)),
                    'balance' => Money::of($allocation->debt->current_balance),
                    'due_day' => $allocation->debt->due_day,
                    'percentage' => Money::percentage($paid, $planned),
                    'status' => $this->progressStatus($paid, $planned),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return $this->rollUp($items, 'planned', 'paid');
    }

    /**
     * @return array<string, mixed>
     */
    private function savings(MonthlyPlan $plan): array
    {
        $items = $plan->savingsAllocations
            ->map(function (PlanSavingsAllocation $allocation) {
                $planned = Money::of($allocation->planned_amount);
                $saved = Money::of($allocation->saved_amount);

                return [
                    'id' => $allocation->id,
                    'savings_goal_id' => $allocation->savings_goal_id,
                    'name' => $allocation->savingsGoal->name,
                    'planned' => $planned,
                    'saved' => $saved,
                    'outstanding' => Money::floorAtZero(Money::sub($planned, $saved)),
                    'goal_balance' => Money::of($allocation->savingsGoal->current_amount),
                    'percentage' => Money::percentage($saved, $planned),
                    'status' => $this->progressStatus($saved, $planned),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return $this->rollUp($items, 'planned', 'saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function spending(MonthlyPlan $plan, CarbonImmutable $today): array
    {
        $monthly = $this->budgets->monthlySummary($plan, $today);
        $weeks = $this->budgets->weeklySummaries($plan, $today);

        return [
            'budget' => $monthly['budget'],
            'spent' => $monthly['spent'],
            'remaining' => $monthly['remaining'],
            'percentage' => $monthly['percentage_used'],
            'status' => $monthly['status'],
            'over_by' => $monthly['over_by'],
            'weeks' => $weeks,
            'weeks_over' => count(array_filter($weeks, fn (array $week) => $week['status'] === 'over')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buffer(MonthlyPlan $plan): array
    {
        $total = Money::of($plan->buffer);
        $used = Money::of($plan->buffer_used);

        return [
            'total' => $total,
            'used' => $used,
            'remaining' => $plan->bufferRemaining(),
            'percentage' => Money::percentage($used, $total),
            'is_intact' => Money::isZero($used),
        ];
    }

    /**
     * Sum a list of rows into the same shape every section reports.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function rollUp(array $items, string $plannedKey, string $settledKey): array
    {
        $planned = Money::sum(array_column($items, $plannedKey));
        $settled = Money::sum(array_column($items, $settledKey));

        return [
            'items' => $items,
            'planned' => $planned,
            'settled' => $settled,
            'outstanding' => Money::floorAtZero(Money::sub($planned, $settled)),
            'count' => count($items),
            'settled_count' => count(array_filter(
                $items,
                fn (array $row) => $row['status'] === 'done',
            )),
            'percentage' => Money::percentage($settled, $planned),
            'status' => $this->progressStatus($settled, $planned),
        ];
    }

    /** done / partial / pending, with nothing owed counting as done. */
    private function progressStatus(string $settled, string $planned): string
    {
        if (Money::isZero($planned) || Money::gte($settled, $planned)) {
            return 'done';
        }

        return Money::isPositive($settled) ? 'partial' : 'pending';
    }
}
