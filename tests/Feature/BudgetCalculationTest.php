<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\BudgetCalculationService;
use App\Services\FinancialPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BudgetCalculationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_weekly_budget_reports_spent_remaining_and_a_daily_limit(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('90000.00');

        // Week 1 runs 25 Sep to 2 Oct (8 days) with a 22,500 budget.
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $this->spend($user, '6500.00', '2026-09-25');

        $summary = app(BudgetCalculationService::class)
            ->weeklySummary($week, CarbonImmutable::parse('2026-09-25'));

        $this->assertSame('22500.00', $summary['budget']);
        $this->assertSame('6500.00', $summary['spent']);
        $this->assertSame('16000.00', $summary['remaining']);
        $this->assertSame(8, $summary['days_remaining']);
        $this->assertSame('2000.00', $summary['recommended_daily']);
        $this->assertSame('safe', $summary['status']);
    }

    #[Test]
    public function the_daily_limit_tightens_as_the_week_runs_down(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('90000.00');

        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $budgets = app(BudgetCalculationService::class);

        // 16,000 left across the last 4 days of the week -> 4,000 a day.
        $this->spend($user, '6500.00', '2026-09-25');
        $onDay5 = $budgets->weeklySummary($week, CarbonImmutable::parse('2026-09-29'));
        $this->assertSame(4, $onDay5['days_remaining']);
        $this->assertSame('4000.00', $onDay5['recommended_daily']);

        // Spending 3,000 more leaves 13,000 across 4 days -> 3,250 a day.
        $this->spend($user, '3000.00', '2026-09-29');
        $after = $budgets->weeklySummary($week->fresh(), CarbonImmutable::parse('2026-09-29'));
        $this->assertSame('13000.00', $after['remaining']);
        $this->assertSame('3250.00', $after['recommended_daily']);
    }

    #[Test]
    public function todays_recommendation_is_the_start_of_day_pace_and_remaining_reflects_spending(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('64000.00');

        // Week 1 gets 16,000 across 8 days: 2,000 a day before anything is spent.
        $this->spend($user, '1250.00', '2026-09-25');

        $daily = app(BudgetCalculationService::class)
            ->dailySummary($plan->fresh(), CarbonImmutable::parse('2026-09-25'));

        $this->assertSame('1250.00', $daily['spent']);
        $this->assertSame('2000.00', $daily['recommended']);
        $this->assertSame('750.00', $daily['remaining']);
    }

    #[Test]
    public function the_daily_limit_is_capped_by_what_the_rest_of_the_month_can_sustain(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('30000.00');

        // Front-load week 1 so its own pace (28,000 over 8 days = 3,500 a day)
        // is far more than the cycle can sustain (30,000 over 30 days = 1,000).
        app(FinancialPlanService::class)->applyWeeklyBudgets($plan, [
            ['week_number' => 1, 'budget_amount' => '28000.00'],
            ['week_number' => 2, 'budget_amount' => '1000.00'],
            ['week_number' => 3, 'budget_amount' => '500.00'],
            ['week_number' => 4, 'budget_amount' => '500.00'],
        ]);

        $budgets = app(BudgetCalculationService::class);
        $today = CarbonImmutable::parse('2026-09-25');

        $daily = $budgets->dailySummary($plan->fresh(['weeklyBudgets']), $today);
        $week = $budgets->weeklySummary(
            $plan->fresh(['weeklyBudgets'])->weeklyBudgets->firstWhere('week_number', 1),
            $today,
        );

        $this->assertSame('3500.00', $week['recommended_daily']);

        // The month-wide pace wins, so one generous week cannot blow the cycle.
        $this->assertSame('1000.00', $daily['recommended']);
    }

    #[Test]
    public function going_over_the_weekly_budget_is_reported_as_over(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('64000.00');

        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $this->spend($user, '18500.00', '2026-09-26');

        $summary = app(BudgetCalculationService::class)
            ->weeklySummary($week, CarbonImmutable::parse('2026-09-28'));

        $this->assertSame('over', $summary['status']);
        $this->assertSame('2500.00', $summary['over_by']);
        $this->assertSame('-2500.00', $summary['remaining']);
    }

    #[Test]
    public function a_budget_at_or_above_its_warning_threshold_reads_as_a_warning(): void
    {
        $budgets = app(BudgetCalculationService::class);

        $this->assertSame('safe', $budgets->statusFor('7900.00', '10000.00')->value);
        $this->assertSame('warning', $budgets->statusFor('8000.00', '10000.00')->value);
        $this->assertSame('warning', $budgets->statusFor('10000.00', '10000.00')->value);
        $this->assertSame('over', $budgets->statusFor('10000.01', '10000.00')->value);

        // A zero budget cannot be exceeded.
        $this->assertSame('safe', $budgets->statusFor('500.00', '0.00')->value);
    }

    #[Test]
    public function category_budgets_track_usage_against_their_own_limit(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('90000.00');

        $user->categories()->where('name', 'Food')->update(['monthly_budget' => '30000.00']);
        $this->spend($user, '24500.00', '2026-09-26', 'Food');

        $summaries = app(BudgetCalculationService::class)->categorySummaries($plan->fresh());
        $food = collect($summaries)->firstWhere('name', 'Food');

        $this->assertSame('24500.00', $food['spent']);
        $this->assertSame('30000.00', $food['budget']);
        $this->assertSame(81.67, $food['percentage_used']);
        $this->assertSame('warning', $food['status']);
    }

    #[Test]
    public function exceeding_a_category_budget_never_blocks_the_expense(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('90000.00');

        $user->categories()->where('name', 'Food')->update(['monthly_budget' => '1000.00']);

        // Well over the category limit, and still accepted.
        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '5000.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-26',
        ])->assertCreated();

        $food = collect(app(BudgetCalculationService::class)->categorySummaries($plan->fresh()))
            ->firstWhere('name', 'Food');

        $this->assertSame('over', $food['status']);
    }

    #[Test]
    public function bills_logged_against_a_recurring_expense_are_kept_out_of_the_spending_budget(): void
    {
        $this->freezeOn('2026-09-25');
        [$user, $plan] = $this->planWithSpending('90000.00');

        $recurring = $user->recurringTransactions()->create([
            'name' => 'Gym',
            'amount' => '3000.00',
            'frequency' => 'monthly',
            'due_day' => 26,
            'amount_type' => 'fixed',
            'start_date' => '2026-01-01',
            'active' => true,
        ]);

        $this->spend($user, '2000.00', '2026-09-26');

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Gym'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'recurring_transaction_id' => $recurring->id,
            'amount' => '3000.00',
            'expense_date' => '2026-09-26',
        ]);

        $monthly = app(BudgetCalculationService::class)
            ->monthlySummary($plan->fresh(), CarbonImmutable::parse('2026-09-26'));

        // Only the discretionary 2,000 counts; the bill is budgeted separately.
        $this->assertSame('2000.00', $monthly['spent']);
    }

    #[Test]
    public function a_future_week_shows_its_whole_budget_as_remaining(): void
    {
        $this->freezeOn('2026-09-25');
        [, $plan] = $this->planWithSpending('90000.00');

        $lastWeek = $plan->weeklyBudgets->firstWhere('week_number', 4);

        $summary = app(BudgetCalculationService::class)
            ->weeklySummary($lastWeek, CarbonImmutable::parse('2026-09-25'));

        $this->assertFalse($summary['is_current']);
        $this->assertSame($summary['budget'], $summary['remaining']);
        $this->assertSame($summary['days_total'], $summary['days_remaining']);
    }

    #[Test]
    public function a_past_week_has_no_days_remaining_and_no_daily_limit(): void
    {
        $this->freezeOn('2026-09-25');
        [, $plan] = $this->planWithSpending('90000.00');

        $firstWeek = $plan->weeklyBudgets->firstWhere('week_number', 1);

        $summary = app(BudgetCalculationService::class)
            ->weeklySummary($firstWeek, CarbonImmutable::parse('2026-10-20'));

        $this->assertTrue($summary['is_past']);
        $this->assertSame(0, $summary['days_remaining']);
        $this->assertSame('0.00', $summary['recommended_daily']);
    }

    /**
     * @return array{0: User, 1: MonthlyPlan}
     */
    private function planWithSpending(string $spendingBudget): array
    {
        $user = $this->makeUser(['base_salary' => '280000.00', 'salary_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user, 2026, 9);

        // Drive the spending budget directly: income with nothing allocated.
        $planner->recordActualIncome($plan, $spendingBudget, applySplit: false);
        $planner->finalize($plan->fresh());

        return [$user->fresh(), $plan->fresh(['weeklyBudgets'])];
    }

    private function spend(User $user, string $amount, string $date, string $category = 'Food'): Expense
    {
        return Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, $category),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $amount,
            'expense_date' => $date,
        ]);
    }
}
