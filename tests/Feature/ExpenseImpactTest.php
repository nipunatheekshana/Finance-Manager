<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpenseImpactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Day 2 of week 1, so the week is live with days still to run.
        $this->freezeOn('2026-09-26');
    }

    #[Test]
    public function it_projects_what_an_expense_would_do_before_saving(): void
    {
        [$user] = $this->activePlan();

        $impact = $this->actingAs($user)
            ->postJson('/api/expenses/preview', [
                'amount' => '5000.00',
                'expense_date' => '2026-09-26',
                'category_id' => $this->categoryId($user, 'Food'),
            ])
            ->assertOk()
            ->json('data');

        // Week 1 has a 16,000 budget and nothing spent yet.
        $this->assertSame('16000.00', $impact['week']['budget']);
        $this->assertSame('0.00', $impact['week']['spent_before']);
        $this->assertSame('5000.00', $impact['week']['spent_after']);
        $this->assertSame('11000.00', $impact['week']['remaining_after']);
        $this->assertSame('safe', $impact['week']['status_after']);
        $this->assertFalse($impact['will_exceed_week']);
    }

    #[Test]
    public function the_preview_writes_nothing(): void
    {
        [$user] = $this->activePlan();

        $this->actingAs($user)->postJson('/api/expenses/preview', [
            'amount' => '5000.00',
            'category_id' => $this->categoryId($user, 'Food'),
        ])->assertOk();

        $this->assertSame(0, Expense::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_flags_an_expense_that_would_tip_the_week_over(): void
    {
        [$user] = $this->activePlan();
        $this->spend($user, '14000.00', '2026-09-26');

        $impact = $this->actingAs($user)
            ->postJson('/api/expenses/preview', [
                'amount' => '4500.00',
                'expense_date' => '2026-09-26',
                'category_id' => $this->categoryId($user, 'Food'),
            ])
            ->assertOk()
            ->json('data');

        // 14,000 + 4,500 against a 16,000 budget is 2,500 over.
        $this->assertTrue($impact['will_exceed_week']);
        $this->assertTrue($impact['needs_decision']);
        $this->assertSame('over', $impact['week']['status_after']);
        $this->assertSame('2500.00', $impact['week']['over_by_after']);
        $this->assertStringContainsString('2,500.00 over', $impact['headline']);
    }

    #[Test]
    public function an_already_overspent_week_is_reported_separately(): void
    {
        [$user] = $this->activePlan();
        $this->spend($user, '18000.00', '2026-09-26');

        $impact = $this->actingAs($user)
            ->postJson('/api/expenses/preview', [
                'amount' => '500.00',
                'expense_date' => '2026-09-26',
                'category_id' => $this->categoryId($user, 'Food'),
            ])
            ->assertOk()
            ->json('data');

        // The week was already over, so this expense is not what tipped it.
        $this->assertTrue($impact['already_over_week']);
        $this->assertFalse($impact['will_exceed_week']);
        $this->assertTrue($impact['needs_decision']);
    }

    #[Test]
    public function it_reports_the_daily_limit_the_expense_would_leave(): void
    {
        [$user] = $this->activePlan();

        $impact = $this->actingAs($user)
            ->postJson('/api/expenses/preview', [
                'amount' => '2000.00',
                'expense_date' => '2026-09-26',
                'category_id' => $this->categoryId($user, 'Food'),
            ])
            ->assertOk()
            ->json('data');

        // 14,000 left across the 7 days remaining in week 1.
        $this->assertSame(7, $impact['week']['days_remaining']);
        $this->assertSame('2000.00', $impact['week']['daily_limit_after']);
    }

    #[Test]
    public function it_flags_a_category_budget_that_would_be_exceeded(): void
    {
        [$user] = $this->activePlan();
        $user->categories()->where('name', 'Food')->update(['monthly_budget' => '3000.00']);

        $impact = $this->actingAs($user)
            ->postJson('/api/expenses/preview', [
                'amount' => '4000.00',
                'expense_date' => '2026-09-26',
                'category_id' => $this->categoryId($user, 'Food'),
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($impact['will_exceed_category']);
        $this->assertSame('Food', $impact['category']['name']);
        $this->assertSame('1000.00', $impact['category']['over_by_after']);
    }

    #[Test]
    public function editing_an_expense_does_not_double_count_its_current_amount(): void
    {
        [$user] = $this->activePlan();
        $expense = $this->spend($user, '10000.00', '2026-09-26');

        $impact = $this->actingAs($user)
            ->postJson('/api/expenses/preview', [
                'amount' => '12000.00',
                'expense_date' => '2026-09-26',
                'category_id' => $this->categoryId($user, 'Food'),
                'expense_id' => $expense->id,
            ])
            ->assertOk()
            ->json('data');

        // Raising 10,000 to 12,000 means 12,000 spent, not 22,000.
        $this->assertSame('0.00', $impact['week']['spent_before']);
        $this->assertSame('12000.00', $impact['week']['spent_after']);
        $this->assertFalse($impact['will_exceed_week']);
    }

    #[Test]
    public function saving_an_expense_reports_whether_the_week_went_over(): void
    {
        [$user] = $this->activePlan();
        $this->spend($user, '14000.00', '2026-09-26');

        $response = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '4500.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-26',
        ])->assertCreated();

        $this->assertTrue($response->json('week.is_over'));
        $this->assertSame('2500.00', $response->json('week.over_by'));
        $this->assertNotNull($response->json('week.weekly_budget_id'));
    }

    #[Test]
    public function a_within_budget_save_reports_the_week_as_fine(): void
    {
        [$user] = $this->activePlan();

        $response = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '1000.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-26',
        ])->assertCreated();

        $this->assertFalse($response->json('week.is_over'));
    }

    #[Test]
    public function the_expense_is_still_recorded_even_when_it_goes_over(): void
    {
        [$user] = $this->activePlan();
        $this->spend($user, '14000.00', '2026-09-26');

        // Going over must never block recording what was actually spent.
        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '9000.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-26',
        ])->assertCreated();

        $this->assertSame(2, Expense::where('user_id', $user->id)->count());
        $this->assertSame('23000.00', \App\Support\Money::of(
            Expense::where('user_id', $user->id)->sum('amount')
        ));
    }

    #[Test]
    public function the_week_reported_after_saving_leads_to_the_adjustment_options(): void
    {
        [$user] = $this->activePlan();
        $this->spend($user, '14000.00', '2026-09-26');

        $weekId = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '4500.00',
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-26',
        ])->json('week.weekly_budget_id');

        // The id handed back opens the choice the user has to make.
        $options = $this->actingAs($user)
            ->getJson("/api/weekly-budgets/{$weekId}/adjustment-options")
            ->assertOk()
            ->json('data');

        $this->assertTrue($options['is_over_budget']);
        $this->assertSame('2500.00', $options['over_by']);
        $this->assertSame(
            ['next_week', 'buffer', 'category', 'ignore'],
            collect($options['options'])->pluck('type')->all(),
        );
    }

    #[Test]
    public function a_preview_without_a_plan_says_so_rather_than_guessing(): void
    {
        $user = $this->makeUser();

        $impact = $this->actingAs($user)
            ->postJson('/api/expenses/preview', ['amount' => '5000.00'])
            ->assertOk()
            ->json('data');

        $this->assertFalse($impact['has_plan']);
        $this->assertFalse($impact['needs_decision']);
        $this->assertNull($impact['week']);
    }

    #[Test]
    public function the_preview_needs_a_positive_amount(): void
    {
        [$user] = $this->activePlan();

        $this->actingAs($user)
            ->postJson('/api/expenses/preview', ['amount' => '0'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function the_preview_cannot_reference_another_accounts_category(): void
    {
        [$user] = $this->activePlan();
        $other = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/expenses/preview', [
                'amount' => '1000.00',
                'category_id' => $this->categoryId($other, 'Food'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    /**
     * A finalised September plan whose weeks are 16,000 each.
     *
     * @return array{0: User, 1: MonthlyPlan}
     */
    private function activePlan(): array
    {
        $user = $this->makeUser(['base_salary' => '280000.00', 'salary_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user, 2026, 9);
        $planner->recordActualIncome($plan, '64000.00', applySplit: false);
        $planner->applyWeeklyBudgets($plan->fresh(), [
            ['week_number' => 1, 'budget_amount' => '16000.00'],
            ['week_number' => 2, 'budget_amount' => '16000.00'],
            ['week_number' => 3, 'budget_amount' => '16000.00'],
            ['week_number' => 4, 'budget_amount' => '16000.00'],
        ]);
        $planner->finalize($plan->fresh());

        return [$user->fresh(), $plan->fresh(['weeklyBudgets'])];
    }

    private function spend(User $user, string $amount, string $date): Expense
    {
        return Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $amount,
            'expense_date' => $date,
        ]);
    }
}
