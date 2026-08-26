<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\CashFlowService;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashFlowAndInsightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-28');
    }

    #[Test]
    public function the_forecast_lists_bills_debt_and_savings_still_to_come(): void
    {
        [$user, $plan] = $this->activePlan();

        $forecast = app(CashFlowService::class)->forecast($plan);

        $this->assertSame('13000.00', $forecast['upcoming_bills']['total']);
        $this->assertSame('100000.00', $forecast['upcoming_debt_payments']['total']);
        $this->assertSame('15000.00', $forecast['planned_savings']['total']);
        $this->assertSame('128000.00', $forecast['total_committed']);
    }

    #[Test]
    public function paying_a_bill_removes_it_from_the_forecast(): void
    {
        [$user, $plan] = $this->activePlan();

        $gym = $plan->fixedExpenses->firstWhere('name', 'Gym');
        $gym->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $forecast = app(CashFlowService::class)->forecast($plan->fresh(['fixedExpenses', 'debtAllocations.debt', 'savingsAllocations.savingsGoal']));

        $this->assertSame('10000.00', $forecast['upcoming_bills']['total']);
    }

    #[Test]
    public function recording_a_debt_payment_removes_it_from_the_forecast(): void
    {
        [$user, $plan] = $this->activePlan();
        $card = $user->debts()->first();

        $this->actingAs($user)
            ->postJson("/api/debts/{$card->id}/payments", [
                'amount' => '100000.00',
                'payment_date' => '2026-09-26',
            ])
            ->assertCreated();

        $forecast = app(CashFlowService::class)->forecast(
            $plan->fresh(['fixedExpenses', 'debtAllocations.debt', 'savingsAllocations.savingsGoal'])
        );

        $this->assertSame('0.00', $forecast['upcoming_debt_payments']['total']);
    }

    #[Test]
    public function the_projected_month_end_balance_is_reported_as_an_estimate(): void
    {
        [, $plan] = $this->activePlan();

        $forecast = app(CashFlowService::class)->forecast($plan);

        $this->assertTrue($forecast['projection_is_estimate']);
        $this->assertArrayHasKey('average_daily_spend', $forecast);
        $this->assertArrayHasKey('projected_further_spend', $forecast);
    }

    #[Test]
    public function the_cash_flow_endpoint_explains_itself_when_there_is_no_plan(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->getJson('/api/cash-flow')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonStructure(['message']);
    }

    #[Test]
    public function the_calendar_lists_salary_bills_and_debts_for_a_month(): void
    {
        [$user] = $this->activePlan();

        $events = $this->actingAs($user)
            ->getJson('/api/calendar?year=2026&month=9')
            ->assertOk()
            ->json('data');

        $kinds = collect($events)->pluck('kind')->unique()->sort()->values()->all();

        $this->assertContains('salary', $kinds);
        $this->assertContains('bill', $kinds);
        $this->assertContains('debt', $kinds);
    }

    #[Test]
    public function an_affordable_purchase_is_reported_as_safe(): void
    {
        [$user] = $this->activePlan();

        $result = $this->actingAs($user)
            ->postJson('/api/affordability-check', ['amount' => '1000.00'])
            ->assertOk()
            ->json('data');

        $this->assertSame('safe', $result['verdict']);
        $this->assertSame('Looks safe', $result['headline']);
        $this->assertNotEmpty($result['disclaimer']);
    }

    #[Test]
    public function a_purchase_beyond_the_remaining_budget_is_not_recommended(): void
    {
        [$user] = $this->activePlan();

        $result = $this->actingAs($user)
            ->postJson('/api/affordability-check', ['amount' => '500000.00'])
            ->assertOk()
            ->json('data');

        $this->assertSame('over', $result['verdict']);
        $this->assertSame('Not recommended', $result['headline']);
        $this->assertNotEmpty($result['reasons']);
    }

    #[Test]
    public function a_large_but_affordable_purchase_is_flagged_as_a_warning(): void
    {
        [$user, $plan] = $this->activePlan();

        // Around 70% of what is left in the current week.
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $amount = \App\Support\Money::mul($week->budget_amount, '0.7');

        $result = $this->actingAs($user)
            ->postJson('/api/affordability-check', ['amount' => $amount])
            ->assertOk()
            ->json('data');

        $this->assertContains($result['verdict'], ['warning', 'over']);
    }

    #[Test]
    public function the_affordability_check_shows_the_figures_behind_the_verdict(): void
    {
        [$user] = $this->activePlan();

        $factors = $this->actingAs($user)
            ->postJson('/api/affordability-check', ['amount' => '1000.00'])
            ->json('data.factors');

        foreach ([
            'month_remaining', 'month_remaining_after', 'week_remaining',
            'week_remaining_after', 'upcoming_bills', 'upcoming_debt_payments',
            'planned_savings', 'buffer_remaining', 'new_daily_limit',
        ] as $key) {
            $this->assertArrayHasKey($key, $factors);
        }
    }

    /**
     * Regression: the affordability check resolves the plan itself, so it
     * arrives without debt or savings relations loaded. Reading them lazily
     * threw the moment a user with debts opened the check.
     */
    #[Test]
    public function the_affordability_check_works_on_a_plan_it_resolved_itself(): void
    {
        [$user] = $this->activePlan();

        // Deliberately not pre-hydrated: exactly how the controller calls it.
        $plan = app(FinancialPlanService::class)->activePlanFor($user);
        $this->assertNotNull($plan);
        $this->assertFalse($plan->relationLoaded('debtAllocations'));

        $result = app(\App\Services\AffordabilityService::class)->check($user, '5000.00');

        $this->assertArrayHasKey('verdict', $result);
        $this->assertSame('100000.00', $result['factors']['upcoming_debt_payments']);
    }

    /**
     * The same shape of bug, reached through the cash-flow screen.
     */
    #[Test]
    public function the_cash_flow_forecast_works_on_a_plan_it_resolved_itself(): void
    {
        [$user] = $this->activePlan();

        $plan = app(FinancialPlanService::class)->activePlanFor($user);
        $forecast = app(CashFlowService::class)->forecast($plan);

        $this->assertSame('100000.00', $forecast['upcoming_debt_payments']['total']);
        $this->assertSame('15000.00', $forecast['planned_savings']['total']);
        $this->assertNotEmpty($forecast['timeline']);
    }

    #[Test]
    public function the_affordability_check_needs_a_positive_amount(): void
    {
        [$user] = $this->activePlan();

        $this->actingAs($user)
            ->postJson('/api/affordability-check', ['amount' => '0'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function the_financial_health_score_is_produced_with_its_factors(): void
    {
        [$user] = $this->activePlan();

        $health = $this->actingAs($user)
            ->getJson('/api/financial-health')
            ->assertOk()
            ->json('data');

        $this->assertTrue($health['has_data']);
        $this->assertIsInt($health['score']);
        $this->assertGreaterThanOrEqual(0, $health['score']);
        $this->assertLessThanOrEqual(100, $health['score']);
        $this->assertCount(6, $health['factors']);

        // The weights must total 100 so the score really is out of 100.
        $this->assertSame(100, array_sum(array_column($health['factors'], 'weight')));

        $this->assertStringContainsString('not a professional', $health['disclaimer']);
    }

    #[Test]
    public function the_health_score_says_so_when_there_is_nothing_to_measure(): void
    {
        $user = $this->makeUser();

        $health = $this->actingAs($user)->getJson('/api/financial-health')->assertOk()->json('data');

        $this->assertFalse($health['has_data']);
        $this->assertNull($health['score']);
    }

    #[Test]
    public function the_dashboard_returns_the_whole_home_screen_in_one_request(): void
    {
        [$user] = $this->activePlan();

        $this->actingAs($user)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period_label', 'has_plan', 'available_to_spend', 'salary',
                    'today_budget', 'week_budget', 'month_budget', 'weeks',
                    'categories', 'debts', 'savings', 'recent_expenses',
                    'upcoming_bills', 'alerts',
                ],
            ]);
    }

    #[Test]
    public function the_dashboard_recalculates_after_an_expense_is_logged(): void
    {
        [$user] = $this->activePlan();

        $before = $this->actingAs($user)->getJson('/api/dashboard')->json('data.available_to_spend');

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '2500.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-28',
        ])->assertCreated();

        $after = $this->actingAs($user)->getJson('/api/dashboard')->json('data.available_to_spend');

        $this->assertSame('2500.00', \App\Support\Money::sub($before, $after));
    }

    #[Test]
    public function a_dashboard_with_no_plan_says_so_rather_than_inventing_figures(): void
    {
        $user = $this->makeUser();

        $data = $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertFalse($data['has_plan']);
        $this->assertSame('0.00', $data['available_to_spend']);
        $this->assertNull($data['today_budget']);
        $this->assertNotEmpty($data['empty_message']);
    }

    /**
     * A finalised September plan with two bills, one card and one savings goal.
     *
     * @return array{0: User, 1: MonthlyPlan}
     */
    private function activePlan(): array
    {
        $user = $this->makeUser(['base_salary' => '280000.00', 'cycle_start_day' => 25]);

        foreach ([
            ['name' => 'Gym', 'amount' => '3000.00', 'due_day' => 26],
            ['name' => 'Koko', 'amount' => '10000.00', 'due_day' => 1],
        ] as $bill) {
            $user->recurringTransactions()->create($bill + [
                'frequency' => 'monthly',
                'amount_type' => 'fixed',
                'start_date' => '2026-01-01',
                'active' => true,
            ]);
        }

        Debt::create([
            'user_id' => $user->id,
            'name' => 'Credit Card',
            'type' => 'credit_card',
            'original_amount' => '377000.00',
            'current_balance' => '377000.00',
            'credit_limit' => '500000.00',
            'minimum_payment' => '18850.00',
            'planned_payment' => '100000.00',
            'due_day' => 1,
            'status' => 'active',
        ]);

        SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency Fund',
            'target_amount' => '300000.00',
            'current_amount' => '50000.00',
            'monthly_target' => '15000.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '15000.00',
            'priority' => 1,
            'status' => 'active',
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);
        $planner->recordActualIncome($plan, '280000.00', applySplit: false);
        $planner->finalize($plan->fresh());

        return [
            $user->fresh(),
            $plan->fresh(['weeklyBudgets', 'fixedExpenses', 'debtAllocations.debt', 'savingsAllocations.savingsGoal', 'user.financialProfile']),
        ];
    }
}
