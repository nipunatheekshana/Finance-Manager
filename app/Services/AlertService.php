<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\FinancialAlert;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Raises the alerts shown on the dashboard.
 *
 * An alert is identified by type + reference + day, so re-running the generator
 * updates the existing row instead of stacking duplicates. Alerts are
 * observations about the user's own plan — they never change it.
 */
class AlertService
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly FinancialPlanService $plans,
        private readonly SalaryCycleService $cycles,
        private readonly RecurringTransactionService $recurring,
    ) {}

    /**
     * Create or refresh one alert. Respects the user's notification settings.
     */
    public function raise(
        User $user,
        AlertType $type,
        string $title,
        string $message,
        AlertSeverity $severity = AlertSeverity::Info,
        string $reference = '',
        ?array $data = null,
        ?string $actionLabel = null,
        ?string $actionRoute = null,
        ?CarbonImmutable $on = null,
    ): ?FinancialAlert {
        if (! $this->isEnabled($user, $type)) {
            return null;
        }

        return FinancialAlert::updateOrCreate(
            [
                'user_id' => $user->id,
                'type' => $type->value,
                'reference' => $reference,
                'triggered_on' => ($on ?? CarbonImmutable::today())->toDateString(),
            ],
            [
                'severity' => $severity->value,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'action_label' => $actionLabel,
                'action_route' => $actionRoute,
            ]
        );
    }

    /**
     * Budget checks that run immediately after an expense is saved, so the user
     * sees the consequence of what they just logged.
     */
    public function afterExpenseRecorded(Expense $expense): void
    {
        $user = $expense->user;
        $plan = $this->plans->activePlanFor($user);

        if ($plan === null) {
            return;
        }

        $this->checkCategoryBudget($user, $plan, $expense->category_id);
        $this->checkWeeklyBudget($user, $plan);

        if ($expense->debt_id !== null) {
            $this->checkCreditCardIncrease($user, $expense);
        }
    }

    /**
     * The daily sweep: salary day, bills, debt due dates, savings milestones and
     * the end-of-week review prompt.
     */
    public function generateFor(User $user, ?CarbonImmutable $today = null): Collection
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $profile = $this->plans->profileFor($user);

        $this->checkSalaryDay($user, $today, $profile->salary_day);
        $this->checkUpcomingBills($user, $today);
        $this->checkDebtDueDates($user, $today);
        $this->checkSavingsGoals($user, $today);

        $this->checkCycleSurplus($user, $today);

        $plan = $this->plans->activePlanFor($user, $today);

        if ($plan !== null) {
            $this->checkWeeklyBudget($user, $plan, $today);
            $this->checkWeeklyReview($user, $plan, $today);

            foreach ($plan->budgetCategories as $budgetCategory) {
                $this->checkCategoryBudget($user, $plan, $budgetCategory->category_id);
            }
        }

        return $this->visibleFor($user);
    }

    /** @return Collection<int, FinancialAlert> */
    public function visibleFor(User $user, int $limit = 20): Collection
    {
        return FinancialAlert::query()
            ->where('user_id', $user->id)
            ->visible()
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'success', 'info')")
            ->orderByDesc('triggered_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function checkSalaryDay(User $user, CarbonImmutable $today, int $salaryDay): void
    {
        $thisMonthSalary = $this->cycles->salaryDate($today->year, $today->month, $salaryDay);
        $nextMonth = $today->addMonthNoOverflow();
        $nextSalary = $this->cycles->salaryDate($nextMonth->year, $nextMonth->month, $salaryDay);

        $upcoming = $thisMonthSalary->gte($today) ? $thisMonthSalary : $nextSalary;

        if ($today->isSameDay($thisMonthSalary)) {
            $period = $this->cycles->planPeriodFor($today, $salaryDay);
            $plan = $user->monthlyPlans()
                ->where('year', $period['year'])
                ->where('month', $period['month'])
                ->first();

            // Only nudge while the plan for this cycle is still unfinished.
            if ($plan === null || $plan->isDraft()) {
                $this->raise(
                    $user,
                    AlertType::SalaryReceived,
                    'Salary day',
                    'Your salary is due today. Set up this month\'s plan to decide where it goes.',
                    AlertSeverity::Success,
                    reference: $today->format('Y-m'),
                    actionLabel: 'Plan this month',
                    actionRoute: '/plan',
                    on: $today,
                );
            }

            return;
        }

        if ($today->addDay()->isSameDay($upcoming)) {
            $this->raise(
                $user,
                AlertType::SalaryTomorrow,
                'Salary day is tomorrow',
                'Your salary is expected tomorrow. Review last month before the new cycle starts.',
                AlertSeverity::Info,
                reference: $upcoming->format('Y-m'),
                actionLabel: 'Review',
                actionRoute: '/reports',
                on: $today,
            );
        }
    }

    private function checkUpcomingBills(User $user, CarbonImmutable $today): void
    {
        $horizon = $today->addDays(3);
        $recurrings = $user->recurringTransactions()->active()->get();

        foreach ($recurrings as $recurring) {
            $dates = $this->recurring->occurrencesBetween($recurring, $today, $horizon);

            if ($dates === []) {
                continue;
            }

            $due = $dates[0];
            $days = $today->diffInDays($due);

            $this->raise(
                $user,
                AlertType::BillDueSoon,
                $recurring->name.' is due '.$this->relativeDay($days),
                'LKR '.number_format((float) $recurring->amount, 2).' for '.$recurring->name.' is due '.$this->relativeDay($days).'.',
                $days <= 1 ? AlertSeverity::Warning : AlertSeverity::Info,
                reference: 'recurring:'.$recurring->id,
                data: ['recurring_transaction_id' => $recurring->id, 'due_date' => $due->toDateString()],
                actionLabel: 'View bills',
                actionRoute: '/settings/recurring',
                on: $today,
            );
        }
    }

    private function checkDebtDueDates(User $user, CarbonImmutable $today): void
    {
        $debts = $user->debts()->active()->whereNotNull('due_day')->get();

        foreach ($debts as $debt) {
            $dueDay = min($debt->due_day, $today->daysInMonth);
            $due = $today->setDay($dueDay);

            if ($due->lt($today)) {
                $next = $today->addMonthNoOverflow()->startOfMonth();
                $due = $next->setDay(min($debt->due_day, $next->daysInMonth));
            }

            $days = $today->diffInDays($due);

            if ($days > 3) {
                continue;
            }

            $this->raise(
                $user,
                AlertType::DebtPaymentDue,
                $debt->name.' payment due '.$this->relativeDay($days),
                'Your '.$debt->name.' payment is due '.$this->relativeDay($days).'.',
                $days <= 2 ? AlertSeverity::Warning : AlertSeverity::Info,
                reference: 'debt:'.$debt->id,
                data: ['debt_id' => $debt->id, 'due_date' => $due->toDateString()],
                actionLabel: 'Open debts',
                actionRoute: '/debts',
                on: $today,
            );
        }
    }

    private function checkSavingsGoals(User $user, CarbonImmutable $today): void
    {
        foreach ($user->savingsGoals()->get() as $goal) {
            if (! $goal->isReached()) {
                continue;
            }

            $this->raise(
                $user,
                AlertType::SavingsTargetReached,
                $goal->name.' target reached',
                'You have reached your '.$goal->name.' target. Nicely done.',
                AlertSeverity::Success,
                reference: 'goal:'.$goal->id,
                data: ['savings_goal_id' => $goal->id],
                actionLabel: 'View savings',
                actionRoute: '/savings',
                on: $today,
            );
        }
    }

    private function checkCategoryBudget(User $user, \App\Models\MonthlyPlan $plan, ?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $summary = collect($this->budgets->categorySummaries($plan))
            ->firstWhere('category_id', $categoryId);

        if ($summary === null || ! $summary['has_budget']) {
            return;
        }

        if ($summary['status'] === 'over') {
            $this->raise(
                $user,
                AlertType::CategoryBudgetExceeded,
                $summary['name'].' budget exceeded',
                'You have gone LKR '.number_format((float) Money::abs($summary['remaining']), 2).' over your '.$summary['name'].' budget.',
                AlertSeverity::Critical,
                reference: 'category:'.$categoryId,
                data: ['category_id' => $categoryId, 'percentage' => $summary['percentage_used']],
                actionLabel: 'View budget',
                actionRoute: '/budget',
            );

            return;
        }

        if ($summary['status'] === 'warning') {
            $this->raise(
                $user,
                AlertType::CategoryBudgetWarning,
                $summary['name'].' budget is '.round($summary['percentage_used']).'% used',
                'LKR '.number_format((float) $summary['remaining'], 2).' left in your '.$summary['name'].' budget this month.',
                AlertSeverity::Warning,
                reference: 'category:'.$categoryId,
                data: ['category_id' => $categoryId, 'percentage' => $summary['percentage_used']],
                actionLabel: 'View budget',
                actionRoute: '/budget',
            );
        }
    }

    private function checkWeeklyBudget(User $user, \App\Models\MonthlyPlan $plan, ?CarbonImmutable $today = null): void
    {
        $week = $this->budgets->currentWeek($plan, $today);

        if ($week === null) {
            return;
        }

        $summary = $this->budgets->weeklySummary($week, $today);

        if ($summary['status'] === 'over') {
            $this->raise(
                $user,
                AlertType::BudgetExceeded,
                'Over your weekly budget',
                'You are LKR '.number_format((float) $summary['over_by'], 2).' over your week '.$week->week_number.' spending budget.',
                AlertSeverity::Critical,
                reference: 'week:'.$week->id,
                data: ['weekly_budget_id' => $week->id, 'over_by' => $summary['over_by']],
                actionLabel: 'Choose what to do',
                actionRoute: '/budget',
                on: $today,
            );

            return;
        }

        if ($summary['status'] === 'warning') {
            $this->raise(
                $user,
                AlertType::BudgetWarning,
                'Weekly budget '.round($summary['percentage_used']).'% used',
                'LKR '.number_format((float) $summary['remaining'], 2).' left for the rest of week '.$week->week_number.'.',
                AlertSeverity::Warning,
                reference: 'week:'.$week->id,
                data: ['weekly_budget_id' => $week->id],
                actionLabel: 'View budget',
                actionRoute: '/budget',
                on: $today,
            );
        }
    }

    private function checkWeeklyReview(User $user, \App\Models\MonthlyPlan $plan, CarbonImmutable $today): void
    {
        $finishedWeek = $plan->weeklyBudgets
            ->first(fn ($week) => CarbonImmutable::instance($week->end_date)->isSameDay($today->subDay()));

        if ($finishedWeek === null) {
            return;
        }

        $this->raise(
            $user,
            AlertType::WeeklyReview,
            'Week '.$finishedWeek->week_number.' is done',
            'Take a minute to review how week '.$finishedWeek->week_number.' went.',
            AlertSeverity::Info,
            reference: 'week:'.$finishedWeek->id,
            data: ['weekly_budget_id' => $finishedWeek->id],
            actionLabel: 'Weekly review',
            actionRoute: '/budget',
            on: $today,
        );
    }

    /**
     * A finished cycle that left money unspent needs a decision, otherwise the
     * money quietly falls out of the plan.
     */
    private function checkCycleSurplus(User $user, CarbonImmutable $today): void
    {
        $pending = app(CycleSurplusService::class)->pendingFor($user, $today);

        if ($pending === null) {
            return;
        }

        $this->raise(
            $user,
            AlertType::CycleSurplus,
            $pending['plan_label'].' left over LKR '.number_format((float) $pending['total'], 2),
            'You did not spend everything last cycle. Decide where it should go before it drifts out of your plan.',
            AlertSeverity::Success,
            reference: 'plan:'.$pending['plan_id'],
            data: ['monthly_plan_id' => $pending['plan_id'], 'total' => $pending['total']],
            actionLabel: 'Decide',
            actionRoute: '/plan',
            on: $today,
        );
    }

    private function checkCreditCardIncrease(User $user, Expense $expense): void
    {
        $debt = Debt::find($expense->debt_id);

        if ($debt === null || ! $debt->isCreditCard()) {
            return;
        }

        $this->raise(
            $user,
            AlertType::CreditCardIncreased,
            $debt->name.' balance went up',
            'New spending of LKR '.number_format((float) $expense->amount, 2).' brought your '.$debt->name.' balance to LKR '.number_format((float) $debt->current_balance, 2).'. Your payoff estimate has been updated.',
            AlertSeverity::Warning,
            reference: 'debt:'.$debt->id,
            data: ['debt_id' => $debt->id, 'balance' => Money::of($debt->current_balance)],
            actionLabel: 'View debt',
            actionRoute: '/debts',
        );
    }

    private function isEnabled(User $user, AlertType $type): bool
    {
        $profile = $user->financialProfile;

        if ($profile === null) {
            return true;
        }

        return $profile->wantsNotification(match ($type) {
            AlertType::SalaryReceived, AlertType::SalaryTomorrow => 'salary_day',
            AlertType::BillDueSoon => 'upcoming_bills',
            AlertType::DebtPaymentDue, AlertType::CreditCardIncreased => 'debt_payments',
            AlertType::BudgetWarning, AlertType::CategoryBudgetWarning => 'budget_warnings',
            AlertType::BudgetExceeded, AlertType::CategoryBudgetExceeded => 'budget_exceeded',
            AlertType::SavingsTargetReached => 'savings_goals',
            AlertType::WeeklyReview => 'weekly_review',
            AlertType::CycleSurplus => 'cycle_surplus',
        });
    }

    private function relativeDay(int $days): string
    {
        return match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'tomorrow',
            default => "in {$days} days",
        };
    }
}
