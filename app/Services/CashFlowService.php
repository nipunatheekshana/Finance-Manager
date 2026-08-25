<?php

namespace App\Services;

use App\Models\MonthlyPlan;
use App\Models\PlanFixedExpense;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What is left now, what is still to come, and where the cycle is likely to end.
 *
 * Powers both the dashboard's "available to spend" figure and the forward-looking
 * cash-flow screen. Everything here is a projection from the current plan.
 */
class CashFlowService
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly SalaryCycleService $cycles,
        private readonly RecurringTransactionService $recurring,
    ) {}

    /**
     * The headline "available to spend" number.
     *
     * This is the discretionary spending budget less what has already been
     * spent. The buffer is deliberately excluded — it is not spending money
     * until the user chooses to release it.
     */
    public function availableToSpend(MonthlyPlan $plan, ?CarbonImmutable $today = null): string
    {
        $monthly = $this->budgets->monthlySummary($plan, $today);

        return Money::of($monthly['remaining']);
    }

    /**
     * Bills in this plan that have not been settled yet.
     *
     * @return array{items: list<array<string, mixed>>, total: string}
     */
    public function upcomingBills(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $items = $plan->fixedExpenses
            ->filter(fn (PlanFixedExpense $row) => $row->status === 'planned')
            ->map(fn (PlanFixedExpense $row) => [
                'id' => $row->id,
                'kind' => 'bill',
                'name' => $row->name,
                'amount' => $row->effectiveAmount(),
                'date' => ($row->postponed_to ?? $row->due_date)?->toDateString(),
                'is_overdue' => $row->due_date !== null
                    && CarbonImmutable::instance($row->due_date)->lt($today),
                'category_id' => $row->category_id,
            ])
            ->sortBy('date')
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => Money::sum(array_column($items, 'amount')),
        ];
    }

    /**
     * Debt payments planned for this cycle that have not been made yet.
     *
     * @return array{items: list<array<string, mixed>>, total: string}
     */
    public function upcomingDebtPayments(MonthlyPlan $plan): array
    {
        $items = $plan->debtAllocations
            ->map(function ($allocation) use ($plan) {
                $outstanding = Money::floorAtZero(
                    Money::sub($allocation->planned_amount, $allocation->paid_amount)
                );

                return [
                    'id' => $allocation->id,
                    'kind' => 'debt',
                    'debt_id' => $allocation->debt_id,
                    'name' => $allocation->debt->name,
                    'amount' => $outstanding,
                    'planned' => Money::of($allocation->planned_amount),
                    'paid' => Money::of($allocation->paid_amount),
                    'date' => $this->debtDueDate($allocation->debt->due_day, $plan),
                ];
            })
            ->filter(fn (array $row) => Money::isPositive($row['amount']))
            ->sortBy('date')
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => Money::sum(array_column($items, 'amount')),
        ];
    }

    /**
     * Savings still to be put aside this cycle.
     *
     * @return array{items: list<array<string, mixed>>, total: string}
     */
    public function plannedSavings(MonthlyPlan $plan): array
    {
        $items = $plan->savingsAllocations
            ->map(function ($allocation) {
                $outstanding = Money::floorAtZero(
                    Money::sub($allocation->planned_amount, $allocation->saved_amount)
                );

                return [
                    'id' => $allocation->id,
                    'kind' => 'savings',
                    'savings_goal_id' => $allocation->savings_goal_id,
                    'name' => $allocation->savingsGoal->name,
                    'amount' => $outstanding,
                    'planned' => Money::of($allocation->planned_amount),
                    'saved' => Money::of($allocation->saved_amount),
                    'date' => null,
                ];
            })
            ->filter(fn (array $row) => Money::isPositive($row['amount']))
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => Money::sum(array_column($items, 'amount')),
        ];
    }

    /**
     * The full picture for the cash-flow screen.
     *
     * @return array<string, mixed>
     */
    public function forecast(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $monthly = $this->budgets->monthlySummary($plan, $today);
        $bills = $this->upcomingBills($plan, $today);
        $debts = $this->upcomingDebtPayments($plan);
        $savings = $this->plannedSavings($plan);

        $income = $plan->totalIncome();
        $committed = Money::add($bills['total'], $debts['total'], $savings['total']);

        // Project the rest of the cycle at the pace set so far. Early in a
        // cycle one big day would distort this, so it is clearly an estimate.
        $daysElapsed = max(1, CarbonImmutable::instance($plan->cycle_start_date)->startOfDay()->diffInDays($today) + 1);
        $daysRemaining = (int) $monthly['days_remaining'];
        $dailyPace = Money::div($monthly['spent'], (string) $daysElapsed);
        $projectedFurtherSpend = Money::mul($dailyPace, (string) $daysRemaining);
        $projectedEnd = Money::sub($monthly['remaining'], $projectedFurtherSpend);

        return [
            'plan_label' => $plan->label(),
            'cycle_start' => $plan->cycle_start_date->toDateString(),
            'cycle_end' => $plan->cycle_end_date->toDateString(),
            'total_income' => $income,
            'available_to_spend' => Money::of($monthly['remaining']),
            'spending_budget' => Money::of($monthly['budget']),
            'spent_so_far' => Money::of($monthly['spent']),
            'upcoming_bills' => $bills,
            'upcoming_debt_payments' => $debts,
            'planned_savings' => $savings,
            'total_committed' => $committed,
            'buffer_remaining' => $plan->bufferRemaining(),
            // Income less every planned commitment: the money set aside for
            // day-to-day spending across the whole cycle.
            'projected_spending_balance' => Money::of($plan->spending_budget),
            'average_daily_spend' => $dailyPace,
            'projected_further_spend' => $projectedFurtherSpend,
            'projected_month_end_balance' => $projectedEnd,
            'projection_is_estimate' => true,
            'days_remaining' => $monthly['days_remaining'],
            'timeline' => $this->timeline($plan, $today),
        ];
    }

    /**
     * Dated events from today to the end of the cycle, income first.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $end = CarbonImmutable::instance($plan->cycle_end_date)->startOfDay();

        $events = [];

        // The salary that funds the next cycle, if it lands before this one ends.
        $profile = $plan->user->financialProfile;
        if ($profile !== null) {
            $nextSalary = $this->cycles->salaryDate(
                $end->addDay()->year,
                $end->addDay()->month,
                $profile->salary_day
            );

            if ($nextSalary->betweenIncluded($today, $end)) {
                $events[] = [
                    'date' => $nextSalary->toDateString(),
                    'kind' => 'income',
                    'name' => 'Salary',
                    'amount' => Money::of($profile->base_salary),
                    'direction' => 'in',
                ];
            }
        }

        foreach ($this->upcomingBills($plan, $today)['items'] as $bill) {
            $events[] = [
                'date' => $bill['date'],
                'kind' => 'bill',
                'name' => $bill['name'],
                'amount' => $bill['amount'],
                'direction' => 'out',
            ];
        }

        foreach ($this->upcomingDebtPayments($plan)['items'] as $debt) {
            $events[] = [
                'date' => $debt['date'],
                'kind' => 'debt',
                'name' => $debt['name'],
                'amount' => $debt['amount'],
                'direction' => 'out',
            ];
        }

        foreach ($this->plannedSavings($plan)['items'] as $goal) {
            $events[] = [
                'date' => $goal['date'] ?? $end->toDateString(),
                'kind' => 'savings',
                'name' => $goal['name'],
                'amount' => $goal['amount'],
                'direction' => 'out',
            ];
        }

        usort($events, fn (array $a, array $b) => ($a['date'] ?? '9999') <=> ($b['date'] ?? '9999'));

        return $events;
    }

    /**
     * Every dated financial event in a calendar month, for the calendar screen.
     *
     * @return list<array<string, mixed>>
     */
    public function calendarEvents(User $user, int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth()->startOfDay();

        $events = [];

        $profile = $user->financialProfile;
        if ($profile !== null) {
            $salary = $this->cycles->salaryDate($year, $month, $profile->salary_day);
            $events[] = [
                'date' => $salary->toDateString(),
                'kind' => 'salary',
                'icon' => 'banknote',
                'name' => 'Salary',
                'amount' => Money::of($profile->base_salary),
                'direction' => 'in',
            ];
        }

        foreach ($user->recurringTransactions()->active()->get() as $recurring) {
            foreach ($this->recurring->occurrencesBetween($recurring, $start, $end) as $date) {
                $events[] = [
                    'date' => $date->toDateString(),
                    'kind' => 'bill',
                    'icon' => 'receipt',
                    'name' => $recurring->name,
                    'amount' => Money::of($recurring->amount),
                    'direction' => 'out',
                ];
            }
        }

        foreach ($user->debts()->active()->whereNotNull('due_day')->get() as $debt) {
            $day = min($debt->due_day, $start->daysInMonth);
            $events[] = [
                'date' => $start->setDay($day)->toDateString(),
                'kind' => 'debt',
                'icon' => 'credit-card',
                'name' => $debt->name,
                'amount' => Money::of($debt->planned_payment),
                'direction' => 'out',
            ];
        }

        $expenses = $user->expenses()
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('expense_date, SUM(amount) as total, COUNT(*) as entries')
            ->groupBy('expense_date')
            ->get();

        foreach ($expenses as $row) {
            $events[] = [
                'date' => CarbonImmutable::parse($row->expense_date)->toDateString(),
                'kind' => 'expense',
                'icon' => 'wallet',
                'name' => $row->entries.' '.($row->entries === 1 ? 'expense' : 'expenses'),
                'amount' => Money::of($row->total),
                'direction' => 'out',
            ];
        }

        foreach ($user->savingsTransactions()
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->with('savingsGoal')
            ->get() as $transaction) {
            $events[] = [
                'date' => $transaction->transaction_date->toDateString(),
                'kind' => 'savings',
                'icon' => 'piggy-bank',
                'name' => $transaction->savingsGoal->name,
                'amount' => Money::of($transaction->amount),
                'direction' => $transaction->type->increasesBalance() ? 'out' : 'in',
            ];
        }

        usort($events, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $events;
    }

    private function debtDueDate(?int $dueDay, MonthlyPlan $plan): ?string
    {
        if ($dueDay === null) {
            return null;
        }

        $start = CarbonImmutable::instance($plan->cycle_start_date)->startOfDay();
        $end = CarbonImmutable::instance($plan->cycle_end_date)->startOfDay();

        // The due day may fall in either calendar month the cycle spans.
        foreach ([$start, $start->addMonthNoOverflow()->startOfMonth()] as $month) {
            $candidate = $month->setDay(min($dueDay, $month->daysInMonth));

            if ($candidate->betweenIncluded($start, $end)) {
                return $candidate->toDateString();
            }
        }

        return null;
    }
}
