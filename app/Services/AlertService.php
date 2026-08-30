<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\FinancialProfile;
use App\Models\FinancialAlert;
use App\Models\WeeklyBudget;
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
        private readonly BudgetCycleService $cycles,
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

        $day = ($on ?? CarbonImmutable::today())->toDateString();

        $values = [
            'severity' => $severity->value,
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'action_label' => $actionLabel,
            'action_route' => $actionRoute,
        ];

        // whereDate() rather than updateOrCreate(): the date cast means the
        // stored value is not always the bare "Y-m-d" the lookup would compare
        // against, and a miss here turns into a unique-constraint violation
        // instead of an update.
        $existing = FinancialAlert::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->where('reference', $reference)
            ->whereDate('triggered_on', $day)
            ->first();

        if ($existing !== null) {
            $existing->fill($values)->save();

            return $existing;
        }

        return FinancialAlert::create($values + [
            'user_id' => $user->id,
            'type' => $type->value,
            'reference' => $reference,
            'triggered_on' => $day,
        ]);
    }

    /**
     * Take down an alert whose condition no longer holds.
     *
     * An alert is a statement about the present. Leaving one up after the
     * overspend has been covered says something untrue, and a banner that
     * cries wolf is one the user learns to scroll past.
     */
    public function withdraw(User $user, AlertType $type, string $reference = ''): void
    {
        FinancialAlert::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->where('reference', $reference)
            ->delete();
    }

    /**
     * Re-check one week after something has changed it, so the banner follows
     * the figures rather than the other way round.
     */
    public function refreshWeek(WeeklyBudget $week): void
    {
        $plan = $week->monthlyPlan;

        $this->checkWeeklyBudget($plan->user, $plan);
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
     * The same checks after a delete: the expense is gone, so the budgets it
     * pushed over may be back within their limits.
     */
    public function afterExpenseDeleted(Expense $expense): void
    {
        $user = $expense->user;
        $plan = $this->plans->activePlanFor($user);

        if ($plan === null) {
            return;
        }

        $this->checkCategoryBudget($user, $plan, $expense->category_id);
        $this->checkWeeklyBudget($user, $plan);
    }

    /**
     * The daily sweep: salary day, bills, debt due dates, savings milestones and
     * the end-of-week review prompt.
     */
    public function generateFor(User $user, ?CarbonImmutable $today = null): Collection
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $profile = $this->plans->profileFor($user);

        $this->checkSalaryDay($user, $today, $profile);
        $this->checkUpcomingBills($user, $today);
        $this->checkDebtDueDates($user, $today);
        $this->checkSavingsGoals($user, $today);

        $this->checkCycleSurplus($user, $today);
        $this->checkIncomeHealth($user, $today);

        $plan = $this->plans->activePlanFor($user, $today);

        if ($plan !== null) {
            $this->checkAllowances($user, $plan, $today);
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
            // A CASE ranking rather than MySQL's FIELD(), which does not exist
            // on other engines and took the dashboard down with it.
            ->orderByRaw(
                'CASE severity'
                ." WHEN 'critical' THEN 1"
                ." WHEN 'warning' THEN 2"
                ." WHEN 'success' THEN 3"
                .' ELSE 4 END'
            )
            ->orderByDesc('triggered_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function checkSalaryDay(User $user, CarbonImmutable $today, FinancialProfile $profile): void
    {
        // A pay day only exists for accounts that actually draw a salary.
        if (! $profile->hasSalary()) {
            return;
        }

        $thisMonthSalary = $this->cycles->cycleStartDate($today->year, $today->month, $profile->cycle_start_day);
        $upcoming = $this->cycles->nextPayDate($profile, $today);

        if ($today->isSameDay($thisMonthSalary)) {
            $period = $this->cycles->periodFor($today, $profile);
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
        $reference = 'week:'.$week->id;

        // Each state withdraws the alerts that belong to the others, so the
        // banner always matches the week's current state.
        if ($summary['status'] !== 'over') {
            $this->withdraw($user, AlertType::BudgetExceeded, $reference);
        }

        if ($summary['status'] !== 'warning') {
            $this->withdraw($user, AlertType::BudgetWarning, $reference);
        }

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

    /**
     * The checks that only matter when income is irregular: is there enough
     * banked to keep drawing, is anyone late paying, and is this cycle short.
     */
    private function checkIncomeHealth(User $user, CarbonImmutable $today): void
    {
        $profile = $user->financialProfile;

        if ($profile === null || ! $profile->hasIrregularIncome()) {
            return;
        }

        $forecast = app(IncomeForecastService::class);

        // ── Runway ────────────────────────────────────────────────────────
        if ($profile->funding_method->usesHoldingPot()) {
            $runway = $forecast->runway($user);

            if ($runway['is_negative']) {
                $this->raise(
                    $user,
                    AlertType::LowRunway,
                    'You have drawn more than you have earned',
                    'Your pot is empty. Either lower what you pay yourself or bring income forward.',
                    AlertSeverity::Critical,
                    reference: 'runway',
                    data: ['balance' => $runway['balance']],
                    actionLabel: 'Review income',
                    actionRoute: '/income',
                    on: $today,
                );
            } elseif ($runway['is_low']) {
                $this->raise(
                    $user,
                    AlertType::LowRunway,
                    'Less than a month of runway left',
                    'LKR '.number_format((float) $runway['balance'], 2).' banked against a draw of LKR '
                        .number_format((float) $runway['draw'], 2).' a month.',
                    AlertSeverity::Warning,
                    reference: 'runway',
                    data: ['months' => $runway['months']],
                    actionLabel: 'Review income',
                    actionRoute: '/income',
                    on: $today,
                );
            }
        }

        // ── Overdue invoices ──────────────────────────────────────────────
        $overdue = $user->incomeTransactions()
            ->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->get();

        if ($overdue->isNotEmpty()) {
            $total = Money::sum($overdue->pluck('amount'));

            $this->raise(
                $user,
                AlertType::InvoiceOverdue,
                $overdue->count().' '.($overdue->count() === 1 ? 'payment is' : 'payments are').' overdue',
                'LKR '.number_format((float) $total, 2).' is past its due date. Money you are owed is not money you have.',
                AlertSeverity::Warning,
                reference: 'overdue',
                data: ['count' => $overdue->count(), 'total' => $total],
                actionLabel: 'See income',
                actionRoute: '/income',
                on: $today,
            );
        }

        // ── A cycle running short of what the plan assumed ────────────────
        $plan = $this->plans->activePlanFor($user, $today);

        if ($plan === null) {
            return;
        }

        $start = CarbonImmutable::instance($plan->cycle_start_date);
        $end = CarbonImmutable::instance($plan->cycle_end_date);
        $daysLeft = $this->cycles->remainingDays($today, $end);
        $elapsed = max(1, $start->diffInDays($today) + 1);
        $total = max(1, $start->diffInDays($end) + 1);

        // Only worth saying once the cycle is more than half gone.
        if ($daysLeft === 0 || $elapsed / $total < 0.5) {
            return;
        }

        $received = $this->incomeReceivedIn($user, $start, $end);
        $planned = Money::of($plan->expected_income);

        if (! Money::isPositive($planned)) {
            return;
        }

        $coverage = Money::percentage($received, $planned);

        if ($coverage >= 70) {
            return;
        }

        $this->raise(
            $user,
            AlertType::IncomeBehindPlan,
            'Income is behind plan this cycle',
            'You have received '.round($coverage).'% of the LKR '.number_format((float) $planned, 2)
                .' this plan assumed, with '.$daysLeft.' '.($daysLeft === 1 ? 'day' : 'days').' to go.',
            AlertSeverity::Warning,
            reference: 'plan:'.$plan->id,
            data: ['coverage' => $coverage, 'received' => $received],
            actionLabel: 'See income',
            actionRoute: '/income',
            on: $today,
        );
    }

    private function incomeReceivedIn(User $user, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return Money::of(
            $user->incomeTransactions()
                ->received()
                ->whereBetween('received_date', [$start->toDateString(), $end->toDateString()])
                ->sum('amount')
        );
    }

    /**
     * Money set aside for gradual spending is worth warning about early: once
     * an allowance is gone the spending does not stop, it starts eating the
     * day-to-day budget instead.
     */
    private function checkAllowances(User $user, \App\Models\MonthlyPlan $plan, CarbonImmutable $today): void
    {
        foreach ($this->budgets->allowanceSummaries($plan, $today) as $allowance) {
            if ($allowance['status'] === 'over') {
                $this->raise(
                    $user,
                    AlertType::AllowanceRunningOut,
                    $allowance['name'].' allowance is used up',
                    'You are LKR '.number_format((float) $allowance['over_by'], 2).' past what you set aside for '
                        .$allowance['name'].'. Anything more comes out of your day-to-day money.',
                    AlertSeverity::Critical,
                    reference: 'allowance:'.$allowance['category_id'],
                    data: ['category_id' => $allowance['category_id']],
                    actionLabel: 'View budget',
                    actionRoute: '/budget',
                    on: $today,
                );

                continue;
            }

            // Spending faster than the cycle is passing, with enough of the
            // cycle left for it to matter.
            if ($allowance['ahead_of_pace'] && $allowance['days_remaining'] > 2 && $allowance['percentage_used'] >= 50) {
                $this->raise(
                    $user,
                    AlertType::AllowanceRunningOut,
                    $allowance['name'].' is going faster than planned',
                    number_format($allowance['percentage_used'], 0).'% of your '.$allowance['name']
                        .' allowance is gone with '.$allowance['days_remaining'].' days left — about LKR '
                        .number_format((float) $allowance['daily_allowance'], 2).' a day from here.',
                    AlertSeverity::Warning,
                    reference: 'allowance:'.$allowance['category_id'],
                    data: ['category_id' => $allowance['category_id']],
                    actionLabel: 'View budget',
                    actionRoute: '/budget',
                    on: $today,
                );
            }
        }
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
            AlertType::SalaryReceived, AlertType::SalaryTomorrow => 'cycle_start_day',
            AlertType::BillDueSoon => 'upcoming_bills',
            AlertType::DebtPaymentDue, AlertType::CreditCardIncreased => 'debt_payments',
            AlertType::BudgetWarning, AlertType::CategoryBudgetWarning => 'budget_warnings',
            AlertType::BudgetExceeded, AlertType::CategoryBudgetExceeded => 'budget_exceeded',
            AlertType::SavingsTargetReached => 'savings_goals',
            AlertType::WeeklyReview => 'weekly_review',
            AlertType::CycleSurplus => 'cycle_surplus',
            AlertType::AllowanceRunningOut => 'budget_warnings',
            AlertType::LowRunway, AlertType::InvoiceOverdue,
            AlertType::IncomeBehindPlan => 'income_health',
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
