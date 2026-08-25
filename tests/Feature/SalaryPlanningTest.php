<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalaryPlanningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_spending_budget_is_income_less_every_allocation(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        // Income 300,000 − fixed 65,000 − debt 110,000 − savings 15,000
        //   − buffer 20,000 = 90,000
        app(FinancialPlanService::class)->recordActualIncome($plan, '300000.00', applySplit: false);
        $plan->refresh();

        $summary = app(FinancialPlanService::class)->allocationSummary($plan);

        $this->assertSame('300000.00', $summary['total_income']);
        $this->assertSame('65000.00', $summary['fixed_expenses']);
        $this->assertSame('110000.00', $summary['debt_payment']);
        $this->assertSame('15000.00', $summary['savings']);
        $this->assertSame('20000.00', $summary['buffer']);
        $this->assertSame('90000.00', $summary['spending_budget']);
        $this->assertFalse($summary['is_over_allocated']);
    }

    #[Test]
    public function a_draft_plan_loads_the_users_recurring_bills(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $names = $plan->fixedExpenses->pluck('name')->sort()->values()->all();
        $this->assertSame(['Gym', 'Koko', 'Lees', 'SLT'], $names);
    }

    #[Test]
    public function skipping_a_bill_removes_it_from_the_plan_total(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $plan = $planner->draftFor($user, 2026, 9);
        $gym = $plan->fixedExpenses->firstWhere('name', 'Gym');

        $this->actingAs($user)
            ->putJson("/api/monthly-plans/{$plan->id}/fixed-expenses/{$gym->id}", ['status' => 'skipped'])
            ->assertOk();

        // 65,000 − 3,000 gym = 62,000
        $this->assertSame('62000.00', $plan->fresh()->fixed_expenses);
    }

    #[Test]
    public function changing_a_bill_amount_only_affects_that_month(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);
        $slt = $plan->fixedExpenses->firstWhere('name', 'SLT');

        $this->actingAs($user)
            ->putJson("/api/monthly-plans/{$plan->id}/fixed-expenses/{$slt->id}", ['actual_amount' => '8000.00'])
            ->assertOk();

        // The plan drops by 2,000, but the recurring transaction is untouched.
        $this->assertSame('63000.00', $plan->fresh()->fixed_expenses);
        $this->assertSame('10000.00', $user->recurringTransactions()->where('name', 'SLT')->value('amount'));
    }

    #[Test]
    public function an_over_allocated_plan_reports_the_shortfall_and_cannot_be_finalised(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $plan = $planner->draftFor($user, 2026, 9);

        // Income 280,000, allocating 65,000 fixed + 150,000 debt (140,000 card
        // plus the 10,000 loan) + 50,000 savings + 20,000 buffer = 285,000.
        $plan->debtAllocations()->where('debt_id', $user->debts()->where('name', 'Credit Card')->value('id'))
            ->update(['planned_amount' => '140000.00']);
        $plan->savingsAllocations()->update(['planned_amount' => '50000.00']);
        $planner->recalculate($plan->fresh());

        $summary = $planner->allocationSummary($plan->fresh());

        $this->assertTrue($summary['is_over_allocated']);
        $this->assertSame('5000.00', $summary['over_allocated_by']);
        $this->assertFalse($summary['can_finalize']);

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/finalize")
            ->assertStatus(422);

        $this->assertSame('draft', $plan->fresh()->status->value);
    }

    #[Test]
    public function an_over_allocated_plan_can_be_finalised_once_a_deficit_is_accepted(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $plan = $planner->draftFor($user, 2026, 9);
        $plan->savingsAllocations()->update(['planned_amount' => '120000.00']);
        $planner->recalculate($plan->fresh());

        $this->assertTrue($planner->allocationSummary($plan->fresh())['is_over_allocated']);

        // The user has to opt in explicitly.
        $this->actingAs($user)
            ->putJson("/api/monthly-plans/{$plan->id}", ['allow_deficit' => true])
            ->assertOk();

        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/finalize")->assertOk();

        $this->assertSame('active', $plan->fresh()->status->value);
    }

    #[Test]
    public function extra_income_is_split_using_the_configured_rule(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $plan = $planner->draftFor($user, 2026, 9);
        $debtBefore = $plan->debt_payment;
        $savingsBefore = $plan->savings;

        // 330,000 actual against 280,000 expected leaves 50,000 extra.
        // 50% debt = 25,000, 30% savings = 15,000, 20% stays as spending.
        $planner->recordActualIncome($plan, '330000.00');
        $plan->refresh();

        $this->assertSame('50000.00', $plan->extra_income);
        $this->assertSame('135000.00', $plan->debt_payment);
        $this->assertSame('30000.00', $plan->savings);

        $this->assertNotSame($debtBefore, $plan->debt_payment);
        $this->assertNotSame($savingsBefore, $plan->savings);
    }

    #[Test]
    public function extra_income_allocated_to_a_debt_never_exceeds_the_balance(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();

        // Leave a card with only a small balance outstanding.
        $user->debts()->where('name', 'Credit Card')->update([
            'current_balance' => '5000.00',
            'planned_payment' => '5000.00',
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user, 2026, 9);
        $planner->recordActualIncome($plan, '400000.00');
        $plan->refresh();

        $allocation = $plan->debtAllocations()
            ->where('debt_id', $user->debts()->where('name', 'Credit Card')->value('id'))
            ->first();

        $this->assertSame('5000.00', $allocation->planned_amount);
    }

    #[Test]
    public function finalising_creates_weekly_budgets_that_sum_to_the_spending_budget(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $plan = $planner->draftFor($user, 2026, 9);
        $planner->recordActualIncome($plan, '300000.00', applySplit: false);
        $planner->finalize($plan->fresh());

        $plan->refresh()->load('weeklyBudgets');

        $this->assertCount(4, $plan->weeklyBudgets);
        $this->assertSame(
            '90000.00',
            \App\Support\Money::sum($plan->weeklyBudgets->pluck('budget_amount')),
        );
    }

    #[Test]
    public function weekly_budgets_do_not_have_to_be_equal(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $plan = $planner->draftFor($user, 2026, 9);
        $planner->recordActualIncome($plan, '300000.00', applySplit: false);

        $this->actingAs($user)->putJson("/api/monthly-plans/{$plan->id}/weeks", [
            'weeks' => [
                ['week_number' => 1, 'budget_amount' => '20000.00'],
                ['week_number' => 2, 'budget_amount' => '25000.00'],
                ['week_number' => 3, 'budget_amount' => '20000.00'],
                ['week_number' => 4, 'budget_amount' => '25000.00'],
            ],
        ])->assertOk();

        $amounts = $plan->fresh()->weeklyBudgets->pluck('budget_amount')->all();
        $this->assertSame(['20000.00', '25000.00', '20000.00', '25000.00'], $amounts);
    }

    #[Test]
    public function only_one_plan_can_be_active_at_a_time(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $september = $planner->draftFor($user, 2026, 9);
        $planner->finalize($september);

        $october = $planner->draftFor($user, 2026, 10);
        $planner->finalize($october);

        $this->assertSame('completed', $september->fresh()->status->value);
        $this->assertSame('active', $october->fresh()->status->value);
    }

    #[Test]
    public function a_completed_plan_can_be_reopened_and_the_change_is_audited(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();
        $planner = app(FinancialPlanService::class);

        $plan = $planner->draftFor($user, 2026, 9);
        $planner->finalize($plan);
        $planner->complete($plan->fresh());

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/reopen", ['reason' => 'Missed an expense'])
            ->assertOk();

        $plan->refresh();
        $this->assertSame('active', $plan->status->value);
        $this->assertNotNull($plan->reopened_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'plan.reopened',
            'auditable_id' => $plan->id,
        ]);
    }

    #[Test]
    public function the_plan_period_follows_the_configured_salary_day(): void
    {
        $this->freezeOn('2026-09-25');
        $user = $this->prepareUser();

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        // Salary day 25 means the September plan runs 25 Sep to 24 Oct.
        $this->assertSame('2026-09-25', $plan->cycle_start_date->toDateString());
        $this->assertSame('2026-10-24', $plan->cycle_end_date->toDateString());
    }

    /**
     * The worked example from the brief: 280,000 salary on day 25, four bills
     * totalling 65,000, two debts and one savings goal.
     */
    private function prepareUser(): User
    {
        $user = $this->makeUser([
            'base_salary' => '280000.00',
            'salary_day' => 25,
            'default_buffer' => '20000.00',
            'extra_debt_percentage' => 50,
            'extra_savings_percentage' => 30,
            'extra_spending_percentage' => 20,
        ]);

        $bills = [
            ['name' => 'Gym', 'amount' => '3000.00', 'due_day' => 26],
            ['name' => 'Lees', 'amount' => '42000.00', 'due_day' => 5],
            ['name' => 'Koko', 'amount' => '10000.00', 'due_day' => 1],
            ['name' => 'SLT', 'amount' => '10000.00', 'due_day' => 28],
        ];

        foreach ($bills as $bill) {
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

        Debt::create([
            'user_id' => $user->id,
            'name' => 'Personal loan',
            'type' => 'loan',
            'original_amount' => '100000.00',
            'current_balance' => '100000.00',
            'minimum_payment' => '10000.00',
            'planned_payment' => '10000.00',
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

        return $user->fresh();
    }
}
