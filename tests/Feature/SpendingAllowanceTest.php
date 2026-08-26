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

/**
 * Money set aside for spending that accumulates through the cycle — fuel,
 * groceries — rather than arriving as a single bill.
 */
class SpendingAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-25');
    }

    #[Test]
    public function an_allowance_is_reserved_out_of_income(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);
        $summary = app(FinancialPlanService::class)->allocationSummary($plan);

        // 280,000 income − 50,000 set aside = 230,000 left to spend day to day.
        $this->assertSame('50000.00', $summary['allowances']);
        $this->assertSame('230000.00', $summary['spending_budget']);
    }

    #[Test]
    public function spending_against_an_allowance_does_not_also_eat_the_daily_budget(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $plan = $this->finalisedPlan($user);

        $budgets = app(BudgetCalculationService::class);
        $before = $budgets->monthlySummary($plan, CarbonImmutable::parse('2026-09-26'));

        // A tank of fuel: comes out of the allowance, not the weekly pool.
        $this->spend($user, '8000.00', 'Transport', '2026-09-26');

        $after = $budgets->monthlySummary($plan->fresh(), CarbonImmutable::parse('2026-09-26'));

        $this->assertSame($before['spent'], $after['spent']);
        $this->assertSame($before['remaining'], $after['remaining']);
    }

    #[Test]
    public function spending_outside_an_allowance_still_counts_normally(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $plan = $this->finalisedPlan($user);

        $this->spend($user, '3000.00', 'Food', '2026-09-26');

        $summary = app(BudgetCalculationService::class)
            ->monthlySummary($plan->fresh(), CarbonImmutable::parse('2026-09-26'));

        $this->assertSame('3000.00', $summary['spent']);
    }

    #[Test]
    public function it_reports_what_is_left_in_the_allowance(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $plan = $this->finalisedPlan($user);

        $this->spend($user, '8000.00', 'Transport', '2026-09-26');
        $this->spend($user, '12000.00', 'Transport', '2026-09-28');

        $allowance = $this->allowanceFor($plan, '2026-09-28');

        $this->assertSame('50000.00', $allowance['allocated']);
        $this->assertSame('20000.00', $allowance['spent']);
        $this->assertSame('30000.00', $allowance['remaining']);
        $this->assertSame(40.0, $allowance['percentage_used']);
        $this->assertSame('safe', $allowance['status']);
    }

    #[Test]
    public function it_spreads_what_is_left_across_the_days_that_remain(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $plan = $this->finalisedPlan($user);

        $this->spend($user, '20000.00', 'Transport', '2026-09-26');

        // 10 days left in the 25 Sep – 24 Oct cycle on 15 October.
        $allowance = $this->allowanceFor($plan, '2026-10-15');

        $this->assertSame(10, $allowance['days_remaining']);
        $this->assertSame('3000.00', $allowance['daily_allowance']);
    }

    #[Test]
    public function it_flags_spending_faster_than_the_cycle_is_passing(): void
    {
        $user = $this->userWithAllowance('Transport', '30000.00');
        $plan = $this->finalisedPlan($user);

        // Over half the fuel gone on day 2 of 30.
        $this->spend($user, '18000.00', 'Transport', '2026-09-26');

        $allowance = $this->allowanceFor($plan, '2026-09-26');

        $this->assertTrue($allowance['ahead_of_pace']);
        $this->assertSame('2000.00', $allowance['expected_by_now']);
        $this->assertSame('16000.00', $allowance['pace_difference']);
    }

    #[Test]
    public function steady_spending_is_not_flagged(): void
    {
        $user = $this->userWithAllowance('Transport', '30000.00');
        $plan = $this->finalisedPlan($user);

        // Half the cycle gone, a little under half the money.
        $this->spend($user, '13000.00', 'Transport', '2026-09-26');

        $allowance = $this->allowanceFor($plan, '2026-10-09');

        $this->assertFalse($allowance['ahead_of_pace']);
    }

    #[Test]
    public function going_over_an_allowance_is_reported_but_never_blocked(): void
    {
        $user = $this->userWithAllowance('Transport', '10000.00');
        $plan = $this->finalisedPlan($user);

        // Recording real spending must always succeed.
        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '13000.00',
            'category_id' => $this->categoryId($user, 'Transport'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-26',
        ])->assertCreated();

        $allowance = $this->allowanceFor($plan, '2026-09-26');

        $this->assertSame('over', $allowance['status']);
        $this->assertSame('3000.00', $allowance['over_by']);
        $this->assertSame('-3000.00', $allowance['remaining']);
    }

    #[Test]
    public function the_allowance_can_be_changed_for_one_cycle_only(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $summary = $this->actingAs($user)
            ->putJson("/api/monthly-plans/{$plan->id}/allowances", [
                'allowances' => [
                    ['category_id' => $this->categoryId($user, 'Transport'), 'amount' => '65000.00'],
                ],
            ])
            ->assertOk()
            ->json('summary');

        $this->assertSame('65000.00', $summary['allowances']);
        $this->assertSame('215000.00', $summary['spending_budget']);

        // The category's standing default is untouched.
        $this->assertSame('50000.00', $user->categories()->where('name', 'Transport')->value('monthly_budget'));
    }

    #[Test]
    public function setting_an_allowance_to_zero_returns_the_money_to_spending(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $summary = $this->actingAs($user)
            ->putJson("/api/monthly-plans/{$plan->id}/allowances", [
                'allowances' => [
                    ['category_id' => $this->categoryId($user, 'Transport'), 'amount' => '0'],
                ],
            ])
            ->assertOk()
            ->json('summary');

        $this->assertSame('0.00', $summary['allowances']);
        $this->assertSame('280000.00', $summary['spending_budget']);

        // And that spending now counts against the daily pool again.
        $this->spend($user, '4000.00', 'Transport', '2026-09-26');
        $monthly = app(BudgetCalculationService::class)
            ->monthlySummary($plan->fresh(), CarbonImmutable::parse('2026-09-26'));
        $this->assertSame('4000.00', $monthly['spent']);
    }

    #[Test]
    public function several_allowances_are_tracked_independently(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $user->categories()->where('name', 'Food')->update([
            'monthly_budget' => '40000.00',
            'is_allowance' => true,
        ]);

        $plan = $this->finalisedPlan($user->fresh());

        $this->spend($user, '10000.00', 'Transport', '2026-09-26');
        $this->spend($user, '25000.00', 'Food', '2026-09-26');

        $rows = collect(app(BudgetCalculationService::class)
            ->allowanceSummaries($plan->fresh(), CarbonImmutable::parse('2026-09-26')));

        $this->assertCount(2, $rows);
        $this->assertSame('40000.00', $rows->firstWhere('name', 'Transport')['remaining']);
        $this->assertSame('15000.00', $rows->firstWhere('name', 'Food')['remaining']);

        // 280,000 − 90,000 reserved = 190,000, and none of it spent yet.
        $monthly = app(BudgetCalculationService::class)
            ->monthlySummary($plan->fresh(), CarbonImmutable::parse('2026-09-26'));
        $this->assertSame('190000.00', $monthly['budget']);
        $this->assertSame('0.00', $monthly['spent']);
    }

    #[Test]
    public function a_plain_category_cap_is_still_only_a_warning(): void
    {
        $user = $this->makeUser(['base_salary' => '280000.00', 'cycle_start_day' => 25]);

        // A cap, not an allowance: it must not reserve anything.
        $user->categories()->where('name', 'Food')->update(['monthly_budget' => '30000.00']);

        $plan = app(FinancialPlanService::class)->draftFor($user->fresh(), 2026, 9);
        $summary = app(FinancialPlanService::class)->allocationSummary($plan);

        $this->assertSame('0.00', $summary['allowances']);
        $this->assertSame('280000.00', $summary['spending_budget']);
    }

    #[Test]
    public function the_dashboard_lists_allowances(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $this->finalisedPlan($user);
        $this->spend($user, '9000.00', 'Transport', '2026-09-26');

        $data = $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->json('data');

        $transport = collect($data['allowances'])->firstWhere('name', 'Transport');

        $this->assertNotNull($transport);
        $this->assertSame('50000.00', $transport['allocated']);
        $this->assertSame('9000.00', $transport['spent']);
    }

    #[Test]
    public function one_account_cannot_change_anothers_allowances(): void
    {
        $user = $this->userWithAllowance('Transport', '50000.00');
        $other = $this->makeUser();
        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $this->actingAs($other)
            ->putJson("/api/monthly-plans/{$plan->id}/allowances", ['allowances' => []])
            ->assertForbidden();

        $this->actingAs($other)
            ->getJson("/api/monthly-plans/{$plan->id}/allowances")
            ->assertForbidden();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function userWithAllowance(string $category, string $amount): User
    {
        $user = $this->makeUser(['base_salary' => '280000.00', 'cycle_start_day' => 25]);

        $user->categories()->where('name', $category)->update([
            'monthly_budget' => $amount,
            'is_allowance' => true,
        ]);

        return $user->fresh();
    }

    private function finalisedPlan(User $user): MonthlyPlan
    {
        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user, 2026, 9);
        $planner->finalize($plan->fresh());

        return $plan->fresh(['weeklyBudgets', 'budgetCategories']);
    }

    /** @return array<string, mixed> */
    private function allowanceFor(MonthlyPlan $plan, string $on): array
    {
        $rows = app(BudgetCalculationService::class)
            ->allowanceSummaries($plan->fresh(), CarbonImmutable::parse($on));

        return $rows[0];
    }

    private function spend(User $user, string $amount, string $category, string $date): void
    {
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, $category),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $amount,
            'expense_date' => $date,
        ]);
    }
}
