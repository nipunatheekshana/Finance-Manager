<?php

namespace App\Services;

use App\Enums\SavingsTransactionType;
use App\Models\DebtPayment;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Models\WeeklyBudget;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Read-only aggregations for the reports screen.
 *
 * Historical balances (debt, savings) are reconstructed backwards from today's
 * balance and the transactions since, so no separate snapshot table is needed
 * and the series always reconciles with the current figures.
 */
class ReportService
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly SavingsService $savings,
        private readonly FinancialPlanService $plans,
    ) {}

    /**
     * Spending grouped by category, largest first.
     *
     * @return array<string, mixed>
     */
    public function spendingByCategory(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Expense::query()
            ->where('expenses.user_id', $user->id)
            ->join('categories', 'categories.id', '=', 'expenses.category_id')
            ->whereBetween('expenses.expense_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('categories.id as category_id, categories.name, categories.icon, categories.color, SUM(expenses.amount) as total, COUNT(*) as entries')
            ->groupBy('categories.id', 'categories.name', 'categories.icon', 'categories.color')
            ->orderByDesc('total')
            ->get();

        $total = Money::sum($rows->pluck('total'));

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total' => $total,
            'categories' => $rows->map(fn ($row) => [
                'category_id' => (int) $row->category_id,
                'name' => $row->name,
                'icon' => $row->icon,
                'color' => $row->color,
                'amount' => Money::of($row->total),
                'entries' => (int) $row->entries,
                'percentage' => Money::percentage($row->total, $total),
            ])->all(),
        ];
    }

    /**
     * Spending over time at daily, weekly or monthly granularity.
     *
     * @return array<string, mixed>
     */
    public function spendingTrend(User $user, string $view, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $expression = $this->dateBucketExpression($view);

        $rows = Expense::query()
            ->where('user_id', $user->id)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("{$expression} as bucket, SUM(amount) as total")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return [
            'view' => $view,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'points' => $rows->map(fn ($row) => [
                'label' => $this->prettyBucket($row->bucket, $view),
                'bucket' => $row->bucket,
                'amount' => Money::of($row->total),
            ])->all(),
        ];
    }

    /**
     * Total debt at the end of each of the last $months months.
     *
     * @return array<string, mixed>
     */
    public function debtTrend(User $user, int $months = 12): array
    {
        $today = CarbonImmutable::today();
        $current = Money::of($user->debts()->sum('current_balance'));

        $points = [];

        for ($i = 0; $i < $months; $i++) {
            $monthEnd = $today->subMonthsNoOverflow($i)->endOfMonth()->startOfDay();
            $cutoff = $monthEnd->gt($today) ? $today : $monthEnd;

            // Walk back from today's balance: undo payments made since, and
            // remove card spending charged since.
            $principalSince = Money::of(
                DebtPayment::query()
                    ->whereHas('debt', fn ($q) => $q->where('user_id', $user->id))
                    ->whereDate('payment_date', '>', $cutoff->toDateString())
                    ->sum('principal_amount')
            );

            $spendingSince = Money::of(
                Expense::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('debt_id')
                    ->whereDate('expense_date', '>', $cutoff->toDateString())
                    ->sum('amount')
            );

            $balance = Money::floorAtZero(
                Money::sub(Money::add($current, $principalSince), $spendingSince)
            );

            $points[] = [
                'label' => $cutoff->format('M Y'),
                'date' => $cutoff->toDateString(),
                'amount' => $balance,
            ];
        }

        return [
            'current_total' => $current,
            'points' => array_reverse($points),
        ];
    }

    /**
     * Total savings at the end of each of the last $months months, plus per-goal
     * progress today.
     *
     * @return array<string, mixed>
     */
    public function savingsTrend(User $user, int $months = 12): array
    {
        $today = CarbonImmutable::today();
        $current = $this->savings->totalSaved($user->id);

        $points = [];

        for ($i = 0; $i < $months; $i++) {
            $monthEnd = $today->subMonthsNoOverflow($i)->endOfMonth()->startOfDay();
            $cutoff = $monthEnd->gt($today) ? $today : $monthEnd;

            $rows = SavingsTransaction::query()
                ->where('user_id', $user->id)
                ->whereDate('transaction_date', '>', $cutoff->toDateString())
                ->selectRaw('type, SUM(amount) as total')
                ->groupBy('type')
                ->pluck('total', 'type');

            $netSince = '0.00';
            foreach ($rows as $type => $total) {
                $netSince = SavingsTransactionType::from($type)->increasesBalance()
                    ? Money::add($netSince, $total)
                    : Money::sub($netSince, $total);
            }

            $points[] = [
                'label' => $cutoff->format('M Y'),
                'date' => $cutoff->toDateString(),
                'amount' => Money::floorAtZero(Money::sub($current, $netSince)),
            ];
        }

        return [
            'current_total' => $current,
            'points' => array_reverse($points),
            'goals' => $user->savingsGoals()->orderBy('priority')->get()->map(fn ($goal) => [
                'id' => $goal->id,
                'name' => $goal->name,
                'current_amount' => Money::of($goal->current_amount),
                'target_amount' => Money::of($goal->target_amount),
                'monthly_target' => Money::of($goal->monthly_target),
                'percentage' => $goal->progressPercentage(),
            ])->all(),
        ];
    }

    /**
     * Income, expenses, debt payments and savings per plan.
     *
     * @return array<string, mixed>
     */
    public function incomeVsExpenses(User $user, int $months = 6): array
    {
        $plans = MonthlyPlan::query()
            ->where('user_id', $user->id)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit($months)
            ->get()
            ->reverse()
            ->values();

        return [
            'points' => $plans->map(fn (MonthlyPlan $plan) => [
                'label' => CarbonImmutable::create($plan->year, $plan->month, 1)->format('M Y'),
                'plan_id' => $plan->id,
                'income' => $plan->totalIncome(),
                'expenses' => $this->totalSpend($plan),
                'debt_payments' => Money::of($plan->debtPayments()->sum('amount')),
                'savings' => $this->savings->netSavedBetween(
                    $user->id,
                    CarbonImmutable::instance($plan->cycle_start_date),
                    CarbonImmutable::instance($plan->cycle_end_date),
                ),
            ])->all(),
        ];
    }

    /**
     * Side-by-side comparison of two plans.
     *
     * @return array<string, mixed>
     */
    public function comparePlans(MonthlyPlan $a, MonthlyPlan $b): array
    {
        return [
            'a' => $this->planSummary($a),
            'b' => $this->planSummary($b),
        ];
    }

    /**
     * The month-end review.
     *
     * @return array<string, mixed>
     */
    public function monthlyReview(MonthlyPlan $plan): array
    {
        $summary = $this->planSummary($plan);
        $previous = MonthlyPlan::query()
            ->where('user_id', $plan->user_id)
            ->where(function ($query) use ($plan) {
                $query->where('year', '<', $plan->year)
                    ->orWhere(fn ($q) => $q->where('year', $plan->year)->where('month', '<', $plan->month));
            })
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        return [
            'plan' => $summary,
            'previous' => $previous ? $this->planSummary($previous) : null,
            'top_categories' => $this->spendingByCategory(
                $plan->user,
                CarbonImmutable::instance($plan->cycle_start_date),
                CarbonImmutable::instance($plan->cycle_end_date),
            )['categories'],
            'weeks' => $this->budgets->weeklySummaries($plan, CarbonImmutable::instance($plan->cycle_end_date)),
        ];
    }

    /**
     * The end-of-week review, with the adjustment options that follow it.
     *
     * @return array<string, mixed>
     */
    public function weeklyReview(WeeklyBudget $week): array
    {
        $plan = $week->monthlyPlan;
        $summary = $this->budgets->weeklySummary($week, CarbonImmutable::instance($week->end_date));

        $start = CarbonImmutable::instance($week->start_date);
        $end = CarbonImmutable::instance($week->end_date);

        $categories = $this->spendingByCategory($plan->user, $start, $end)['categories'];

        return [
            'week' => $summary,
            'top_category' => $categories[0] ?? null,
            'categories' => $categories,
            'savings' => $this->savings->netSavedBetween($plan->user_id, $start, $end),
            'debt_payments' => Money::of(
                DebtPayment::query()
                    ->whereHas('debt', fn ($q) => $q->where('user_id', $plan->user_id))
                    ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
                    ->sum('amount')
            ),
            'is_over_budget' => $summary['status'] === 'over',
            'over_by' => $summary['over_by'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planSummary(MonthlyPlan $plan): array
    {
        $start = CarbonImmutable::instance($plan->cycle_start_date);
        $end = CarbonImmutable::instance($plan->cycle_end_date);

        $spendingBudget = Money::of($plan->spending_budget);
        $discretionary = $this->budgets->spentBetween($plan->user_id, $start, $end);

        // 100% means spending landed exactly on budget; overspending drops it.
        $adherence = Money::isPositive($spendingBudget)
            ? max(0.0, min(100.0, 100 - max(0.0, Money::percentage($discretionary, $spendingBudget) - 100)))
            : null;

        return [
            'plan_id' => $plan->id,
            'label' => $plan->label(),
            'year' => $plan->year,
            'month' => $plan->month,
            'status' => $plan->status->value,
            'income' => $plan->totalIncome(),
            'expenses' => $this->totalSpend($plan),
            'discretionary_spend' => $discretionary,
            'fixed_expenses' => Money::of($plan->fixed_expenses),
            'spending_budget' => $spendingBudget,
            'debt_payments' => Money::of($plan->debtPayments()->sum('amount')),
            'savings' => $this->savings->netSavedBetween($plan->user_id, $start, $end),
            'buffer' => Money::of($plan->buffer),
            'buffer_used' => Money::of($plan->buffer_used),
            'budget_adherence' => $adherence === null ? null : round($adherence, 1),
            'credit_card_reduction' => $this->creditCardReduction($plan),
        ];
    }

    /** Every expense in the cycle, bills included. */
    private function totalSpend(MonthlyPlan $plan): string
    {
        return Money::of(
            Expense::query()
                ->where('user_id', $plan->user_id)
                ->whereBetween('expense_date', [
                    $plan->cycle_start_date->toDateString(),
                    $plan->cycle_end_date->toDateString(),
                ])
                ->sum('amount')
        );
    }

    /** Net movement on credit cards during the cycle: payments less new spending. */
    private function creditCardReduction(MonthlyPlan $plan): string
    {
        $paid = Money::of(
            DebtPayment::query()
                ->whereHas('debt', fn ($q) => $q->where('user_id', $plan->user_id)->where('type', 'credit_card'))
                ->whereBetween('payment_date', [
                    $plan->cycle_start_date->toDateString(),
                    $plan->cycle_end_date->toDateString(),
                ])
                ->sum('principal_amount')
        );

        $charged = Money::of(
            Expense::query()
                ->where('user_id', $plan->user_id)
                ->whereHas('debt', fn ($q) => $q->where('type', 'credit_card'))
                ->whereBetween('expense_date', [
                    $plan->cycle_start_date->toDateString(),
                    $plan->cycle_end_date->toDateString(),
                ])
                ->sum('amount')
        );

        return Money::sub($paid, $charged);
    }

    /**
     * Group expenses by day, ISO week or month.
     *
     * DATE_FORMAT is MySQL-specific, which is deliberate: the app targets
     * MySQL and is tested against it. Anything else would need this rewritten.
     */
    private function dateBucketExpression(string $view): string
    {
        return match ($view) {
            'daily' => "DATE_FORMAT(expense_date, '%Y-%m-%d')",
            'weekly' => "DATE_FORMAT(expense_date, '%x-W%v')",
            default => "DATE_FORMAT(expense_date, '%Y-%m')",
        };
    }

    private function prettyBucket(string $bucket, string $view): string
    {
        return match ($view) {
            'daily' => CarbonImmutable::parse($bucket)->format('j M'),
            'weekly' => str_replace('-W', ' W', $bucket),
            default => CarbonImmutable::parse($bucket.'-01')->format('M Y'),
        };
    }
}
