<?php

namespace App\Services;

use App\Enums\AllocationType;
use App\Enums\PlanStatus;
use App\Models\Debt;
use App\Models\FinancialProfile;
use App\Models\MonthlyPlan;
use App\Models\PlanDebtAllocation;
use App\Models\PlanFixedExpense;
use App\Models\PlanSavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place a monthly plan is calculated.
 *
 *     total income
 *   − fixed expenses
 *   − debt payments
 *   − savings
 *   − buffer
 *   = spending budget
 *
 * Spending budget is always derived, never stored independently, so the plan
 * can never disagree with its own parts.
 */
class FinancialPlanService
{
    public function __construct(
        private readonly BudgetCycleService $cycles,
        private readonly RecurringTransactionService $recurring,
        private readonly CycleSurplusService $surplus,
        private readonly IncomeForecastService $income,
    ) {}

    /**
     * Fetch the draft/active plan for a period, creating and pre-filling a draft
     * from the user's recurring bills, debts and savings goals if none exists.
     */
    public function draftFor(User $user, int $year, int $month): MonthlyPlan
    {
        $profile = $this->profileFor($user);

        $existing = MonthlyPlan::query()
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing) {
            return $existing;
        }

        [$start, $end] = $this->cycles->cycleFor($year, $month, $profile);

        // Salary, a self-paid draw, a forecast, or only what has arrived —
        // whichever this account is set up for.
        $funding = $this->income->fundingFor($user, $year, $month, $profile);

        return DB::transaction(function () use ($user, $profile, $year, $month, $start, $end, $funding) {
            $plan = MonthlyPlan::create([
                'user_id' => $user->id,
                'year' => $year,
                'month' => $month,
                'funding_method' => $funding['method'],
                'drawn_amount' => $funding['drawn_amount'],
                'expected_income' => $funding['amount'],
                'actual_income' => null,
                'extra_income' => '0.00',
                // Whatever the previous cycle chose to hand forward.
                'opening_balance' => $this->surplus->openingBalanceFor($user, $year, $month),
                'buffer' => $profile->default_buffer,
                'cycle_start_date' => $start->toDateString(),
                'cycle_end_date' => $end->toDateString(),
                'status' => PlanStatus::Draft->value,
            ]);

            $this->seedFixedExpenses($plan);
            $this->seedAllowances($plan);
            $this->seedDebtAllocations($plan);
            $this->seedSavingsAllocations($plan);

            return $this->recalculate($plan->fresh());
        });
    }

    /**
     * Pull the user's live recurring bills into the plan as editable rows.
     */
    public function seedFixedExpenses(MonthlyPlan $plan): void
    {
        $recurrings = $plan->user->recurringTransactions()->active()->get();
        $rows = $this->recurring->expandForCycle($recurrings, $plan->cycle_start_date, $plan->cycle_end_date);

        foreach ($rows as $row) {
            /** @var \App\Models\RecurringTransaction $rt */
            $rt = $row['recurring'];

            PlanFixedExpense::updateOrCreate(
                [
                    'monthly_plan_id' => $plan->id,
                    'recurring_transaction_id' => $rt->id,
                ],
                [
                    'category_id' => $rt->category_id,
                    'payment_method_id' => $rt->payment_method_id,
                    'name' => $rt->name,
                    'amount' => $row['total'],
                    'occurrences' => $row['occurrences'],
                    'due_date' => $row['first_due']->toDateString(),
                    'status' => 'planned',
                ]
            );
        }
    }

    /**
     * Reserve the categories the user spends against gradually — fuel,
     * groceries, eating out. Unlike a bill these have no due date and no single
     * payment; the money is set aside and drawn down over the cycle.
     */
    public function seedAllowances(MonthlyPlan $plan): void
    {
        $categories = $plan->user->categories()->active()->allowances()->get();

        foreach ($categories as $category) {
            $plan->budgetCategories()->updateOrCreate(
                ['category_id' => $category->id],
                [
                    'is_allowance' => true,
                    'budget_amount' => Money::of($category->monthly_budget),
                ],
            );
        }
    }

    /**
     * Recommend a payment for each active debt: the user's planned payment when
     * they have set one, otherwise the minimum, never more than the balance.
     */
    public function seedDebtAllocations(MonthlyPlan $plan): void
    {
        $debts = $plan->user->debts()->active()->get();

        foreach ($debts as $debt) {
            $recommended = $this->recommendedDebtPayment($debt);

            PlanDebtAllocation::updateOrCreate(
                [
                    'monthly_plan_id' => $plan->id,
                    'debt_id' => $debt->id,
                ],
                [
                    'minimum_payment' => Money::min($debt->minimum_payment, $debt->current_balance),
                    'recommended_payment' => $recommended,
                    'planned_amount' => $recommended,
                ]
            );
        }
    }

    public function recommendedDebtPayment(Debt $debt): string
    {
        $planned = Money::of($debt->planned_payment);
        $minimum = Money::of($debt->minimum_payment);

        $target = Money::isPositive($planned) ? $planned : $minimum;

        // Never plan to pay more than is actually owed.
        return Money::min($target, Money::of($debt->current_balance));
    }

    /**
     * Recommend a contribution per savings goal from its allocation rule.
     */
    public function seedSavingsAllocations(MonthlyPlan $plan): void
    {
        $goals = $plan->user->savingsGoals()->active()->orderBy('priority')->get();
        $income = $plan->totalIncome();

        foreach ($goals as $goal) {
            $recommended = $this->recommendedSavingsAmount($goal, $income, Money::of($plan->extra_income));

            PlanSavingsAllocation::updateOrCreate(
                [
                    'monthly_plan_id' => $plan->id,
                    'savings_goal_id' => $goal->id,
                ],
                [
                    'recommended_amount' => $recommended,
                    'planned_amount' => $recommended,
                ]
            );
        }
    }

    public function recommendedSavingsAmount(SavingsGoal $goal, string $income, string $extraIncome = '0.00'): string
    {
        $amount = match ($goal->allocation_type) {
            AllocationType::SalaryPercentage => Money::percentOf($income, $goal->allocation_value),
            AllocationType::ExtraPercentage => Money::percentOf($extraIncome, $goal->allocation_value),
            AllocationType::Custom => Money::of($goal->allocation_value),
            AllocationType::Fixed => Money::of($goal->monthly_target),
        };

        // Don't over-fund a goal past its target.
        return Money::min($amount, $goal->remainingAmount());
    }

    /**
     * Re-derive every total on the plan from its constituent rows.
     */
    public function recalculate(MonthlyPlan $plan): MonthlyPlan
    {
        $plan->loadMissing(['fixedExpenses', 'debtAllocations', 'savingsAllocations', 'budgetCategories']);

        $fixed = Money::sum(
            $plan->fixedExpenses
                ->filter(fn (PlanFixedExpense $row) => $row->countsTowardPlan())
                ->map(fn (PlanFixedExpense $row) => $row->effectiveAmount())
        );

        // Money set aside for gradual spending. Reserved here and excluded from
        // the weekly pool, so it is never counted twice.
        $allowances = Money::sum(
            $plan->budgetCategories
                ->filter(fn ($row) => (bool) $row->is_allowance)
                ->pluck('budget_amount')
        );

        $debt = Money::sum($plan->debtAllocations->pluck('planned_amount'));
        $savings = Money::sum($plan->savingsAllocations->pluck('planned_amount'));

        $income = $plan->totalIncome();
        $buffer = Money::of($plan->buffer);

        $spending = Money::sub(
            $income,
            Money::add($fixed, $allowances, $debt, $savings, $buffer)
        );

        $plan->forceFill([
            'fixed_expenses' => $fixed,
            'allowances' => $allowances,
            'debt_payment' => $debt,
            'savings' => $savings,
            'spending_budget' => $spending,
        ])->save();

        return $plan;
    }

    /**
     * The allocation breakdown shown in planning step 5, plus the
     * over-allocation verdict.
     *
     * @return array<string, mixed>
     */
    public function allocationSummary(MonthlyPlan $plan): array
    {
        $plan = $this->recalculate($plan);

        $income = $plan->totalIncome();
        $spending = Money::of($plan->spending_budget);
        $overAllocated = Money::isNegative($spending);
        $allocated = Money::add(
            $plan->fixed_expenses,
            $plan->debt_payment,
            $plan->savings,
            $plan->buffer,
            Money::floorAtZero($spending),
        );

        return [
            'total_income' => $income,
            'salary_income' => $plan->salaryIncome(),
            'expected_income' => Money::of($plan->expected_income),
            'actual_income' => $plan->actual_income === null ? null : Money::of($plan->actual_income),
            'extra_income' => Money::of($plan->extra_income),
            'opening_balance' => Money::of($plan->opening_balance),
            'fixed_expenses' => Money::of($plan->fixed_expenses),
            'allowances' => Money::of($plan->allowances),
            'debt_payment' => Money::of($plan->debt_payment),
            'savings' => Money::of($plan->savings),
            'buffer' => Money::of($plan->buffer),
            'spending_budget' => $spending,
            'total_allocated' => $allocated,
            'is_over_allocated' => $overAllocated,
            'over_allocated_by' => Money::abs(Money::min($spending, '0')),
            'allow_deficit' => (bool) $plan->allow_deficit,
            'can_finalize' => ! $overAllocated || (bool) $plan->allow_deficit,
            // Shares of income, for the allocation chart.
            'breakdown' => [
                ['key' => 'fixed_expenses', 'label' => 'Fixed Expenses', 'amount' => Money::of($plan->fixed_expenses), 'percentage' => Money::percentage($plan->fixed_expenses, $income)],
                ['key' => 'allowances', 'label' => 'Allowances', 'amount' => Money::of($plan->allowances), 'percentage' => Money::percentage($plan->allowances, $income)],
                ['key' => 'debt_payment', 'label' => 'Debt', 'amount' => Money::of($plan->debt_payment), 'percentage' => Money::percentage($plan->debt_payment, $income)],
                ['key' => 'savings', 'label' => 'Savings', 'amount' => Money::of($plan->savings), 'percentage' => Money::percentage($plan->savings, $income)],
                ['key' => 'buffer', 'label' => 'Buffer', 'amount' => Money::of($plan->buffer), 'percentage' => Money::percentage($plan->buffer, $income)],
                ['key' => 'spending', 'label' => 'Spending', 'amount' => Money::floorAtZero($spending), 'percentage' => Money::percentage(Money::floorAtZero($spending), $income)],
            ],
        ];
    }

    /**
     * Record the salary that actually landed and split any surplus using the
     * user's configured extra-income rule.
     */
    public function recordActualIncome(MonthlyPlan $plan, string $actualIncome, bool $applySplit = true): MonthlyPlan
    {
        $profile = $this->profileFor($plan->user);

        $actual = Money::of($actualIncome);
        $extra = Money::floorAtZero(Money::sub($actual, $plan->expected_income));

        $plan->forceFill([
            'actual_income' => $actual,
            'extra_income' => $extra,
        ])->save();

        if ($applySplit && Money::isPositive($extra)) {
            $this->applyExtraIncomeSplit($plan->fresh(), $profile, $extra);
        }

        return $this->recalculate($plan->fresh());
    }

    /**
     * Distribute surplus income across debt / savings / spending.
     *
     * Spending's share needs no action: it simply stays in the derived spending
     * budget once debt and savings have taken their cut.
     */
    public function applyExtraIncomeSplit(MonthlyPlan $plan, FinancialProfile $profile, string $extra): void
    {
        $toDebt = Money::percentOf($extra, (string) $profile->extra_debt_percentage);
        $toSavings = Money::percentOf($extra, (string) $profile->extra_savings_percentage);

        if (Money::isPositive($toDebt)) {
            $this->distributeToDebts($plan, $toDebt);
        }

        if (Money::isPositive($toSavings)) {
            $this->distributeToSavings($plan, $toSavings);
        }
    }

    /** Highest-interest debt first, capped at each balance. */
    private function distributeToDebts(MonthlyPlan $plan, string $amount): void
    {
        $allocations = $plan->debtAllocations()
            ->with('debt')
            ->get()
            ->sortByDesc(fn (PlanDebtAllocation $a) => (float) ($a->debt->interest_rate ?? 0));

        $left = $amount;

        foreach ($allocations as $allocation) {
            if (! Money::isPositive($left)) {
                break;
            }

            $headroom = Money::floorAtZero(
                Money::sub($allocation->debt->current_balance, $allocation->planned_amount)
            );
            $add = Money::min($left, $headroom);

            if (Money::isPositive($add)) {
                $allocation->forceFill([
                    'planned_amount' => Money::add($allocation->planned_amount, $add),
                ])->save();

                $left = Money::sub($left, $add);
            }
        }
    }

    /** Highest priority (lowest number) first, capped at each goal's shortfall. */
    private function distributeToSavings(MonthlyPlan $plan, string $amount): void
    {
        $allocations = $plan->savingsAllocations()
            ->with('savingsGoal')
            ->get()
            ->sortBy(fn (PlanSavingsAllocation $a) => $a->savingsGoal->priority);

        $left = $amount;

        foreach ($allocations as $allocation) {
            if (! Money::isPositive($left)) {
                break;
            }

            $headroom = Money::floorAtZero(
                Money::sub($allocation->savingsGoal->remainingAmount(), $allocation->planned_amount)
            );
            $add = Money::min($left, $headroom);

            if (Money::isPositive($add)) {
                $allocation->forceFill([
                    'planned_amount' => Money::add($allocation->planned_amount, $add),
                ])->save();

                $left = Money::sub($left, $add);
            }
        }
    }

    /**
     * Suggest an even weekly split of the spending budget. The user is free to
     * change any week afterwards — nothing forces equal allocation.
     *
     * @return list<array{week_number: int, start_date: string, end_date: string, days: int, budget_amount: string}>
     */
    public function suggestWeeklyBudgets(MonthlyPlan $plan): array
    {
        $windows = $this->cycles->weekWindows($plan->cycle_start_date, $plan->cycle_end_date);
        $slices = Money::split(Money::floorAtZero($plan->spending_budget), count($windows));

        $suggestions = [];

        foreach ($windows as $index => $window) {
            $suggestions[] = [
                'week_number' => $window['week_number'],
                'start_date' => $window['start_date']->toDateString(),
                'end_date' => $window['end_date']->toDateString(),
                'days' => $window['days'],
                'budget_amount' => $slices[$index] ?? '0.00',
            ];
        }

        return $suggestions;
    }

    /**
     * Persist the weekly split, keeping any amounts the user has edited.
     *
     * @param  array<int, array{week_number: int, budget_amount: mixed}>|null  $overrides
     */
    public function applyWeeklyBudgets(MonthlyPlan $plan, ?array $overrides = null): MonthlyPlan
    {
        $suggestions = $this->suggestWeeklyBudgets($plan);

        $byWeek = [];
        foreach ($overrides ?? [] as $override) {
            $byWeek[(int) $override['week_number']] = Money::of($override['budget_amount']);
        }

        DB::transaction(function () use ($plan, $suggestions, $byWeek) {
            $keptWeekNumbers = [];

            foreach ($suggestions as $suggestion) {
                $weekNumber = $suggestion['week_number'];
                $keptWeekNumbers[] = $weekNumber;

                $plan->weeklyBudgets()->updateOrCreate(
                    ['week_number' => $weekNumber],
                    [
                        'start_date' => $suggestion['start_date'],
                        'end_date' => $suggestion['end_date'],
                        'budget_amount' => $byWeek[$weekNumber] ?? $suggestion['budget_amount'],
                    ]
                );
            }

            // Drop weeks that no longer exist (e.g. after a salary-day change).
            $plan->weeklyBudgets()->whereNotIn('week_number', $keptWeekNumbers)->delete();
        });

        return $plan->load('weeklyBudgets');
    }

    /**
     * Lock the plan in. Refuses an over-allocated plan unless the user has
     * explicitly opted into a deficit.
     *
     * @throws RuntimeException
     */
    public function finalize(MonthlyPlan $plan): MonthlyPlan
    {
        $summary = $this->allocationSummary($plan);

        if (! $summary['can_finalize']) {
            throw new RuntimeException(
                'This plan allocates '.$summary['over_allocated_by'].' more than the available income.'
            );
        }

        return DB::transaction(function () use ($plan) {
            if ($plan->weeklyBudgets()->count() === 0) {
                $this->applyWeeklyBudgets($plan);
            }

            // Only one plan can be active at a time.
            MonthlyPlan::query()
                ->where('user_id', $plan->user_id)
                ->where('id', '!=', $plan->id)
                ->where('status', PlanStatus::Active->value)
                ->update(['status' => PlanStatus::Completed->value, 'completed_at' => now()]);

            $plan->forceFill([
                'status' => PlanStatus::Active->value,
                'finalized_at' => now(),
            ])->save();

            $this->attachExistingExpenses($plan);

            return $plan->fresh(['weeklyBudgets']);
        });
    }

    /**
     * Link expenses already logged inside this cycle to the plan and its weeks,
     * so a plan created part-way through a cycle still sees them.
     */
    public function attachExistingExpenses(MonthlyPlan $plan): void
    {
        $plan->loadMissing('weeklyBudgets');

        foreach ($plan->weeklyBudgets as $week) {
            \App\Models\Expense::query()
                ->where('user_id', $plan->user_id)
                ->whereBetween('expense_date', [
                    $week->start_date->toDateString(),
                    $week->end_date->toDateString(),
                ])
                ->update([
                    'monthly_plan_id' => $plan->id,
                    'weekly_budget_id' => $week->id,
                ]);
        }
    }

    public function complete(MonthlyPlan $plan): MonthlyPlan
    {
        $plan->forceFill([
            'status' => PlanStatus::Completed->value,
            'completed_at' => now(),
        ])->save();

        return $plan;
    }

    /** Reopen a finished month for corrections; the audit trail records who/when. */
    public function reopen(MonthlyPlan $plan): MonthlyPlan
    {
        $plan->forceFill([
            'status' => PlanStatus::Active->value,
            'completed_at' => null,
            'reopened_at' => now(),
        ])->save();

        return $plan;
    }

    /**
     * The plan covering today, if the user has finalised one.
     */
    public function activePlanFor(User $user, ?CarbonImmutable $today = null): ?MonthlyPlan
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        return MonthlyPlan::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [PlanStatus::Active->value, PlanStatus::Completed->value])
            ->whereDate('cycle_start_date', '<=', $today->toDateString())
            ->whereDate('cycle_end_date', '>=', $today->toDateString())
            ->with('weeklyBudgets')
            ->orderByDesc('status')
            ->first();
    }

    public function profileFor(User $user): FinancialProfile
    {
        return $user->financialProfile
            ?? $user->financialProfile()->create(['base_salary' => 0, 'cycle_start_day' => 25]);
    }
}
