<?php

namespace App\Services;

use App\Enums\DebtType;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Builds the entire dashboard in one pass so the mobile app can render its
 * first screen from a single request.
 *
 * Every figure here is derived from the database at read time — nothing on the
 * dashboard is stored or hard-coded.
 */
class DashboardService
{
    public function __construct(
        private readonly FinancialPlanService $plans,
        private readonly BudgetCalculationService $budgets,
        private readonly CashFlowService $cashFlow,
        private readonly SavingsService $savings,
        private readonly DebtPayoffService $payoff,
        private readonly AlertService $alerts,
        private readonly BudgetCycleService $cycles,
        private readonly IncomeForecastService $income,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $profile = $this->plans->profileFor($user);
        $plan = $this->plans->activePlanFor($user, $today);

        $salary = $this->salarySection($user, $profile, $plan, $today);

        if ($plan === null) {
            return [
                'today' => $today->toDateString(),
                'has_plan' => false,
                'onboarding_completed' => $profile->hasCompletedOnboarding(),
                'period_label' => $today->format('F Y'),
                'salary' => $salary,
                'available_to_spend' => '0.00',
                'today_budget' => null,
                'week_budget' => null,
                'month_budget' => null,
                'debts' => $this->debtSection($user),
                'savings' => $this->savingsSection($user, null),
                'recent_expenses' => $this->recentExpenses($user),
                'upcoming_bills' => ['items' => [], 'total' => '0.00'],
                'upcoming_debt_payments' => ['items' => [], 'total' => '0.00'],
                'alerts' => $this->alerts->visibleFor($user, 6),
                'empty_message' => 'Set up a monthly plan to see your budgets here.',
            ];
        }

        $plan->loadMissing(['weeklyBudgets', 'fixedExpenses', 'debtAllocations.debt', 'savingsAllocations.savingsGoal']);

        $monthly = $this->budgets->monthlySummary($plan, $today);
        $daily = $this->budgets->dailySummary($plan, $today);
        $currentWeek = $this->budgets->currentWeek($plan, $today);

        return [
            'today' => $today->toDateString(),
            'has_plan' => true,
            'onboarding_completed' => $profile->hasCompletedOnboarding(),
            'plan_id' => $plan->id,
            'plan_status' => $plan->status->value,
            'period_label' => $plan->label(),
            'cycle_start' => $plan->cycle_start_date->toDateString(),
            'cycle_end' => $plan->cycle_end_date->toDateString(),
            'salary' => $salary,
            'available_to_spend' => Money::of($monthly['remaining']),
            'today_budget' => $daily,
            'week_budget' => $currentWeek ? $this->budgets->weeklySummary($currentWeek, $today) : null,
            'month_budget' => $monthly,
            'weeks' => $this->budgets->weeklySummaries($plan, $today),
            'categories' => $this->budgets->categorySummaries($plan),
            'allowances' => $this->budgets->allowanceSummaries($plan, $today),
            'debts' => $this->debtSection($user),
            'savings' => $this->savingsSection($user, $plan),
            'recent_expenses' => $this->recentExpenses($user),
            'upcoming_bills' => $this->cashFlow->upcomingBills($plan, $today),
            'upcoming_debt_payments' => $this->cashFlow->upcomingDebtPayments($plan),
            'alerts' => $this->alerts->visibleFor($user, 6),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salarySection(User $user, \App\Models\FinancialProfile $profile, ?\App\Models\MonthlyPlan $plan, CarbonImmutable $today): array
    {
        $thisMonthSalary = $this->cycles->cycleStartDate($today->year, $today->month, $profile->cycle_start_day);
        $nextSalary = $this->cycles->nextPayDate($profile, $today);

        $period = $this->cycles->periodFor($today, $profile);
        $currentPlan = $plan ?? $user->monthlyPlans()
            ->where('year', $period['year'])
            ->where('month', $period['month'])
            ->first();

        $funding = $this->income->fundingFor($user, $period['year'], $period['month'], $profile);
        $isSalaried = $profile->hasSalary();

        return [
            'income_mode' => $profile->income_mode->value,
            'funding_method' => $profile->funding_method->value,
            'funding_label' => $funding['method_label'],
            'funding_explanation' => $funding['explanation'] ?? null,
            // Irregular accounts have no pay day; the pot and its runway are
            // what matters to them instead.
            'has_pay_day' => $isSalaried,
            'holding_pot' => $profile->funding_method->usesHoldingPot()
                ? $this->income->runway($user)
                : null,
            'received_this_cycle' => $plan === null ? '0.00' : $this->income->summaryBetween(
                $user,
                CarbonImmutable::instance($plan->cycle_start_date),
                CarbonImmutable::instance($plan->cycle_end_date),
            ),
            'expected' => Money::of($funding['amount']),
            'actual' => $currentPlan?->actual_income === null ? null : Money::of($currentPlan->actual_income),
            'extra' => $currentPlan === null ? '0.00' : Money::of($currentPlan->extra_income),
            'cycle_start_day' => $profile->cycle_start_day,
            'next_salary_date' => $isSalaried ? $nextSalary->toDateString() : null,
            'days_until_salary' => $isSalaried ? $today->diffInDays($nextSalary) : null,
            'is_salary_day' => $isSalaried && $today->isSameDay($thisMonthSalary),
            // Prompt the salary-day flow while the cycle's plan is unfinished.
            'needs_planning' => $currentPlan === null || $currentPlan->isDraft(),
            'plan_period' => $period,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function debtSection(User $user): array
    {
        $debts = $user->debts()->active()->orderByDesc('current_balance')->get();

        // Cards are ranked by balance so the one that matters most leads, but
        // every card is returned: an account can hold any number of them and
        // each carries its own balance and payoff.
        $creditCards = $debts
            ->filter(fn ($debt) => $debt->type === DebtType::CreditCard)
            ->sortByDesc(fn ($debt) => (float) $debt->current_balance)
            ->values();

        $primaryCard = $creditCards->first();

        return [
            'total_balance' => Money::sum($debts->pluck('current_balance')),
            'total_planned_payment' => Money::sum($debts->pluck('planned_payment')),
            'count' => $debts->count(),
            'credit_card' => $primaryCard === null ? null : [
                'id' => $primaryCard->id,
                'name' => $primaryCard->name,
                'balance' => Money::of($primaryCard->current_balance),
                'planned_payment' => Money::of($primaryCard->planned_payment),
                'progress_percentage' => $primaryCard->progressPercentage(),
                'utilisation_percentage' => $primaryCard->utilisationPercentage(),
                'payoff' => $this->payoff->project($primaryCard),
            ],
            'credit_cards' => [
                'count' => $creditCards->count(),
                'total_balance' => Money::sum($creditCards->pluck('current_balance')),
                'total_planned_payment' => Money::sum($creditCards->pluck('planned_payment')),
                'total_limit' => Money::sum($creditCards->pluck('credit_limit')),
                'items' => $creditCards->map(fn ($card) => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'balance' => Money::of($card->current_balance),
                    'planned_payment' => Money::of($card->planned_payment),
                    'progress_percentage' => $card->progressPercentage(),
                    'utilisation_percentage' => $card->utilisationPercentage(),
                ])->all(),
            ],
            'items' => $debts->map(fn ($debt) => [
                'id' => $debt->id,
                'name' => $debt->name,
                'type' => $debt->type->value,
                'type_label' => $debt->type->label(),
                'balance' => Money::of($debt->current_balance),
                'planned_payment' => Money::of($debt->planned_payment),
                'progress_percentage' => $debt->progressPercentage(),
                'remaining_installments' => $debt->remaining_installments,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function savingsSection(User $user, ?\App\Models\MonthlyPlan $plan): array
    {
        $goals = $user->savingsGoals()->orderBy('priority')->get();

        $thisCycle = $plan === null ? '0.00' : $this->savings->netSavedBetween(
            $user->id,
            CarbonImmutable::instance($plan->cycle_start_date),
            CarbonImmutable::instance($plan->cycle_end_date),
        );

        return [
            'total' => Money::sum($goals->pluck('current_amount')),
            'this_month' => $thisCycle,
            'target_total' => Money::sum($goals->pluck('target_amount')),
            'count' => $goals->count(),
            'goals' => $goals->map(fn ($goal) => [
                'id' => $goal->id,
                'name' => $goal->name,
                'icon' => $goal->icon,
                'current_amount' => Money::of($goal->current_amount),
                'target_amount' => Money::of($goal->target_amount),
                'monthly_target' => Money::of($goal->monthly_target),
                'percentage' => $goal->progressPercentage(),
                'status' => $goal->status,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentExpenses(User $user, int $limit = 6): array
    {
        return $user->expenses()
            ->with(['category:id,name,icon,color', 'paymentMethod:id,name,icon'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($expense) => [
                'id' => $expense->id,
                'amount' => Money::of($expense->amount),
                'description' => $expense->description,
                'expense_date' => $expense->expense_date->toDateString(),
                'category' => [
                    'id' => $expense->category->id,
                    'name' => $expense->category->name,
                    'icon' => $expense->category->icon,
                    'color' => $expense->category->color,
                ],
                'payment_method' => [
                    'id' => $expense->paymentMethod->id,
                    'name' => $expense->paymentMethod->name,
                    'icon' => $expense->paymentMethod->icon,
                ],
            ])
            ->all();
    }
}
