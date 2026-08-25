<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AccountSetupService;
use App\Services\BudgetCalculationService;
use App\Services\CardPaymentMethodService;
use App\Services\DebtPaymentService;
use App\Services\FinancialPlanService;
use App\Services\ExpenseService;
use App\Services\SalaryCycleService;
use App\Services\SavingsService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A realistic worked example: one demo account mid-way through a salary cycle,
 * with recurring bills, a credit card being paid down, an installment debt, an
 * emergency fund and a few weeks of logged spending.
 *
 * Nothing here is referenced by application logic — it is data the user can
 * change or delete entirely from the UI.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $setup = app(AccountSetupService::class);
        $plans = app(FinancialPlanService::class);
        $cycles = app(SalaryCycleService::class);
        $savings = app(SavingsService::class);
        $debtPayments = app(DebtPaymentService::class);
        $cards = app(CardPaymentMethodService::class);
        $budgets = app(BudgetCalculationService::class);

        $user = User::firstOrCreate(
            ['email' => 'demo@financemanager.test'],
            ['name' => 'Demo User', 'password' => Hash::make('password')],
        );

        $setup->prepare($user);
        $user->refresh();

        $today = CarbonImmutable::today();

        // ── Step 1: salary ────────────────────────────────────────────────
        $user->financialProfile->forceFill([
            'base_salary' => '280000.00',
            'salary_day' => 25,
            'has_extra_income' => true,
            'default_buffer' => '20000.00',
            'extra_debt_percentage' => 50,
            'extra_savings_percentage' => 30,
            'extra_spending_percentage' => 20,
            'onboarding_completed_at' => now(),
        ])->save();

        $user->incomeSources()->firstOrCreate(
            ['name' => 'Salary'],
            ['type' => 'salary', 'expected_amount' => '280000.00', 'active' => true],
        );

        $categories = $user->categories()->pluck('id', 'name');
        $methods = $user->paymentMethods()->pluck('id', 'name');

        // ── Step 2: recurring expenses ────────────────────────────────────
        // Amounts are per occurrence; the planner multiplies by how many times
        // each one actually falls inside the cycle.
        $recurring = [
            [
                'name' => 'Gym',
                'amount' => '3000.00',
                'amount_type' => 'fixed',
                'category_id' => $categories['Gym'],
                'payment_method_id' => $methods['Bank Account'],
                'frequency' => 'monthly',
                'due_day' => 26,
            ],
            [
                'name' => 'Koko',
                'amount' => '10000.00',
                'amount_type' => 'estimated',
                'category_id' => $categories['Bills'],
                'payment_method_id' => $methods['Koko'],
                'frequency' => 'monthly',
                'due_day' => 1,
            ],
            [
                // A variable bill: planned at the expected figure, with the real
                // range recorded so the user can log what actually arrives.
                'name' => 'SLT',
                'amount' => '9000.00',
                'minimum_amount' => '8000.00',
                'maximum_amount' => '10000.00',
                'amount_type' => 'range',
                'category_id' => $categories['Bills'],
                'payment_method_id' => $methods['Bank Account'],
                'frequency' => 'monthly',
                'due_day' => 28,
            ],
            [
                // 2 packs a week at LKR 3,200 — counted from real weekdays in
                // the cycle, never assumed to be four weeks.
                'name' => 'Cigarettes',
                'amount' => '6400.00',
                'amount_type' => 'estimated',
                'category_id' => $categories['Smoking'],
                'payment_method_id' => $methods['Cash'],
                'frequency' => 'weekly',
                'day_of_week' => CarbonImmutable::MONDAY,
            ],
            [
                // 5g a month at LKR 5,500.
                'name' => 'Weed',
                'amount' => '27500.00',
                'amount_type' => 'estimated',
                'category_id' => $categories['Personal'],
                'payment_method_id' => $methods['Cash'],
                'frequency' => 'monthly',
                'due_day' => 5,
            ],
        ];

        foreach ($recurring as $row) {
            $user->recurringTransactions()->firstOrCreate(
                ['name' => $row['name']],
                $row + [
                    'start_date' => $today->subMonths(6)->startOfMonth()->toDateString(),
                    'active' => true,
                ],
            );
        }

        // ── Step 3: debts ─────────────────────────────────────────────────
        $creditCard = $user->debts()->firstOrCreate(
            ['name' => 'Credit Card'],
            [
                'type' => 'credit_card',
                'original_amount' => '377000.00',
                'current_balance' => '377000.00',
                'credit_limit' => '500000.00',
                // Left unset so the payoff estimate is the plain no-interest
                // projection; the user can add their real rate in Settings.
                'interest_rate' => null,
                'minimum_payment' => '18850.00',
                'planned_payment' => '100000.00',
                'due_day' => 1,
                'status' => 'active',
            ],
        );

        // Each card gets its own payment method, so spending is charged to the
        // card it actually went on.
        $cards->ensureFor($creditCard);

        // A second card, to show that every card is tracked independently.
        $storeCard = $user->debts()->firstOrCreate(
            ['name' => 'Store Card'],
            [
                'type' => 'credit_card',
                'original_amount' => '48000.00',
                'current_balance' => '48000.00',
                'credit_limit' => '75000.00',
                'interest_rate' => null,
                'minimum_payment' => '2400.00',
                'planned_payment' => '12000.00',
                'due_day' => 10,
                'status' => 'active',
            ],
        );

        $cards->ensureFor($storeCard);

        $lees = $user->debts()->firstOrCreate(
            ['name' => 'Lees'],
            [
                'type' => 'installment',
                // 11 installments of LKR 42,000 still to run.
                'original_amount' => '504000.00',
                'current_balance' => '462000.00',
                'minimum_payment' => '42000.00',
                'planned_payment' => '42000.00',
                'installment_amount' => '42000.00',
                'remaining_installments' => 11,
                'due_day' => 5,
                'status' => 'active',
            ],
        );

        // ── Step 4: savings ───────────────────────────────────────────────
        $emergencyFund = $user->savingsGoals()->firstOrCreate(
            ['name' => 'Emergency Fund'],
            [
                'icon' => 'shield',
                'target_amount' => '300000.00',
                'current_amount' => '50000.00',
                'monthly_target' => '15000.00',
                'allocation_type' => 'fixed',
                'allocation_value' => '15000.00',
                'priority' => 1,
                'status' => 'active',
            ],
        );

        // ── Category budgets ──────────────────────────────────────────────
        $budgetsByCategory = [
            'Food' => '30000.00',
            'Transport' => '12000.00',
            'Smoking' => '30000.00',
            'Entertainment' => '8000.00',
            'Shopping' => '10000.00',
        ];

        foreach ($budgetsByCategory as $name => $amount) {
            $user->categories()->where('name', $name)->update(['monthly_budget' => $amount]);
        }

        // ── A finished previous cycle, left unsettled ─────────────────────
        // Gives the month-end "what should happen to the leftover" prompt
        // something real to work with the moment the demo is opened.
        $this->seedPreviousCycle($user, $cycles, $plans, $today);

        // ── The current cycle's plan ──────────────────────────────────────
        $period = $cycles->currentPeriodFor($user->financialProfile, $today);
        $plan = $plans->draftFor($user, $period['year'], $period['month']);

        if ($plan->isDraft()) {
            // Salary came in slightly above the base this month.
            $plans->recordActualIncome($plan, '300000.00', applySplit: false);
            $plan->refresh();

            $plans->applyWeeklyBudgets($plan);
            $plans->finalize($plan);
            $plan->refresh();

            // The salary itself.
            $plan->incomeTransactions()->firstOrCreate(
                ['user_id' => $user->id, 'received_date' => $plan->cycle_start_date->toDateString()],
                [
                    'amount' => '300000.00',
                    'type' => 'base',
                    'description' => 'Salary for '.$plan->label(),
                ],
            );
        }

        $plan->load('weeklyBudgets');

        // ── Payments already made this cycle ──────────────────────────────
        if ($creditCard->payments()->count() === 0) {
            $debtPayments->recordPayment($creditCard, [
                'amount' => '100000.00',
                'payment_date' => $plan->cycle_start_date->addDays(2)->toDateString(),
                'monthly_plan_id' => $plan->id,
                'notes' => 'Monthly payoff installment',
            ]);
        }

        if ($lees->payments()->count() === 0) {
            $debtPayments->recordPayment($lees, [
                'amount' => '42000.00',
                'payment_date' => $plan->cycle_start_date->addDays(11)->toDateString(),
                'monthly_plan_id' => $plan->id,
                'reduce_installment' => true,
                'notes' => 'Installment '.(12 - $lees->remaining_installments),
            ]);
        }

        if ($emergencyFund->transactions()->count() === 0) {
            $savings->deposit($emergencyFund, [
                'amount' => '15000.00',
                'transaction_date' => $plan->cycle_start_date->addDays(1)->toDateString(),
                'description' => 'Monthly contribution',
                'monthly_plan_id' => $plan->id,
            ]);
        }

        // ── A few weeks of day-to-day spending ────────────────────────────
        if ($user->expenses()->count() === 0) {
            $this->seedExpenses($user, $plan, $categories, $methods, $today);
        }

        $budgets->refreshWeeklySpend($plan->fresh('weeklyBudgets'));

        $this->command?->info('Demo account ready: demo@financemanager.test / password');
    }

    /**
     * The cycle before this one: finalised, under-spent, and still waiting on a
     * decision about what to do with what is left.
     */
    private function seedPreviousCycle(
        User $user,
        SalaryCycleService $cycles,
        FinancialPlanService $plans,
        CarbonImmutable $today,
    ): void {
        $current = $cycles->currentPeriodFor($user->financialProfile, $today);
        $previousMonth = CarbonImmutable::create($current['year'], $current['month'], 1)
            ->subMonthNoOverflow();

        $existing = $user->monthlyPlans()
            ->where('year', $previousMonth->year)
            ->where('month', $previousMonth->month)
            ->first();

        if ($existing !== null) {
            return;
        }

        $plan = $plans->draftFor($user, $previousMonth->year, $previousMonth->month);
        $plans->recordActualIncome($plan, '280000.00', applySplit: false);
        $plans->finalize($plan->fresh());
        $plan->refresh();

        // Spend roughly two thirds of that cycle's budget, spread across it.
        $budget = (float) $plan->spending_budget;
        $target = round($budget * 0.62, 2);

        $expenses = app(ExpenseService::class);
        $categories = $user->categories()->pluck('id', 'name');
        $cash = $user->paymentMethods()->where('name', 'Cash')->value('id');

        $perEntry = round($target / 12, 2);
        $start = CarbonImmutable::instance($plan->cycle_start_date);

        for ($i = 0; $i < 12; $i++) {
            $expenses->create($user, [
                'category_id' => $categories[$i % 2 === 0 ? 'Food' : 'Transport'],
                'payment_method_id' => $cash,
                'amount' => number_format($perEntry, 2, '.', ''),
                'expense_date' => $start->addDays($i * 2)->toDateString(),
                'description' => 'Everyday spending',
            ]);
        }

        // Completed, but deliberately left unresolved so the prompt appears.
        $plans->complete($plan->fresh());
    }

    /**
     * Deterministic sample spending from the start of the cycle up to today.
     */
    private function seedExpenses(User $user, $plan, $categories, $methods, CarbonImmutable $today): void
    {
        // Day-to-day spending only. Cigarettes and Weed are already planned as
        // recurring bills, so logging them here too would double-count them.
        $patterns = [
            ['category' => 'Food', 'method' => 'Cash', 'items' => [
                ['Rice & curry', '850.00'], ['Kottu', '1200.00'], ['Groceries', '3800.00'],
                ['Breakfast', '450.00'], ['Lunch with the team', '1600.00'],
            ]],
            ['category' => 'Transport', 'method' => 'Cash', 'items' => [
                ['Uber', '1200.00'], ['Tuk to office', '400.00'], ['Fuel', '4000.00'],
            ]],
            ['category' => 'Food', 'method' => 'Debit Card', 'items' => [
                ['Supermarket run', '2650.00'], ['Coffee', '650.00'],
            ]],
            ['category' => 'Entertainment', 'method' => 'Credit Card', 'items' => [
                ['Cinema', '2500.00'],
            ]],
            ['category' => 'Health', 'method' => 'Cash', 'items' => [
                ['Pharmacy', '1450.00'],
            ]],
        ];

        $start = CarbonImmutable::instance($plan->cycle_start_date);
        $expenses = app(\App\Services\ExpenseService::class);

        $dayOffset = 0;
        $patternIndex = 0;

        while ($start->addDays($dayOffset)->lte($today)) {
            $date = $start->addDays($dayOffset);
            $pattern = $patterns[$patternIndex % count($patterns)];
            $item = $pattern['items'][$dayOffset % count($pattern['items'])];

            $expenses->create($user, [
                'category_id' => $categories[$pattern['category']],
                'payment_method_id' => $methods[$pattern['method']],
                'amount' => $item[1],
                'expense_date' => $date->toDateString(),
                'description' => $item[0],
            ]);

            $patternIndex++;
            // Roughly one entry every other day.
            $dayOffset += ($patternIndex % 3 === 0) ? 3 : 2;
        }
    }
}
