<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\BudgetCalculationService;
use App\Services\CycleSurplusService;
use App\Services\FinancialPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens to allowance money that is not spent — and to spending that
 * goes past it.
 */
class AllowanceLeftoverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function money_left_in_an_allowance_becomes_part_of_the_cycle_surplus(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('20000.00');

        // Only half the fuel money used.
        $this->spend($user, '8000.00', 'Transport', '2026-09-26');

        $this->freezeOn('2026-10-28');
        $surplus = app(CycleSurplusService::class)->summarise($plan->fresh());

        // 20,000 set aside, 8,000 spent, so 12,000 is still in the bank.
        $this->assertSame('12000.00', $surplus['unused_allowances']);
        $this->assertTrue($surplus['has_surplus']);
    }

    #[Test]
    public function the_surplus_total_adds_budget_allowances_and_buffer_together(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('20000.00', buffer: '10000.00');

        $this->spend($user, '5000.00', 'Transport', '2026-09-26');
        $this->spend($user, '4000.00', 'Food', '2026-09-27');

        $this->freezeOn('2026-10-28');
        $surplus = app(CycleSurplusService::class)->summarise($plan->fresh());

        $expected = \App\Support\Money::add(
            $surplus['unspent_budget'],
            $surplus['unused_allowances'],
            $surplus['unused_buffer'],
        );

        $this->assertSame($expected, $surplus['total']);
        $this->assertSame('15000.00', $surplus['unused_allowances']);
    }

    #[Test]
    public function allowance_spending_is_not_charged_against_the_spending_budget_at_cycle_end(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('20000.00');

        // Spent entirely within the allowance.
        $this->spend($user, '20000.00', 'Transport', '2026-09-26');

        $this->freezeOn('2026-10-28');
        $surplus = app(CycleSurplusService::class)->summarise($plan->fresh());

        // The spending budget is untouched: this came out of the allowance.
        $this->assertSame('0.00', $surplus['spent']);
        $this->assertSame($surplus['spending_budget'], $surplus['unspent_budget']);
        $this->assertSame('0.00', $surplus['unused_allowances']);
    }

    #[Test]
    public function spending_past_an_allowance_spills_into_the_day_to_day_budget(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('10000.00');

        // 3,000 more than was set aside for fuel.
        $this->spend($user, '13000.00', 'Transport', '2026-09-26');

        $monthly = app(BudgetCalculationService::class)
            ->monthlySummary($plan->fresh(), CarbonImmutable::parse('2026-09-26'));

        // Only the excess reaches the day-to-day pool, exactly as the app says.
        $this->assertSame('3000.00', $monthly['spent']);
    }

    #[Test]
    public function the_spill_respects_what_was_already_used_in_earlier_weeks(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('10000.00');

        // Week 1 uses the whole allowance.
        $this->spend($user, '10000.00', 'Transport', '2026-09-26');
        // Week 2 has none left, so all of it spills.
        $this->spend($user, '2500.00', 'Transport', '2026-10-05');

        $budgets = app(BudgetCalculationService::class);
        $plan = $plan->fresh(['weeklyBudgets', 'budgetCategories']);

        $weekOne = $budgets->weeklySummary(
            $plan->weeklyBudgets->firstWhere('week_number', 1),
            CarbonImmutable::parse('2026-10-05'),
        );
        $weekTwo = $budgets->weeklySummary(
            $plan->weeklyBudgets->firstWhere('week_number', 2),
            CarbonImmutable::parse('2026-10-05'),
        );

        $this->assertSame('0.00', $weekOne['spent'], 'week 1 was fully covered by the allowance');
        $this->assertSame('2500.00', $weekTwo['spent'], 'week 2 had no allowance left');
    }

    #[Test]
    public function an_unused_allowance_can_be_swept_into_a_savings_goal(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('20000.00');
        $this->spend($user, '5000.00', 'Transport', '2026-09-26');

        $goal = \App\Models\SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency Fund',
            'target_amount' => '300000.00',
            'current_amount' => '0.00',
            'monthly_target' => '0.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '0.00',
            'priority' => 1,
            'status' => 'active',
        ]);

        $this->freezeOn('2026-10-28');
        $total = app(CycleSurplusService::class)->summarise($plan->fresh())['total'];

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [
                    ['type' => 'savings', 'savings_goal_id' => $goal->id, 'amount' => $total],
                ],
            ])
            ->assertOk();

        $this->assertSame($total, $goal->fresh()->current_amount);
    }

    /**
     * A September cycle with a Transport allowance and nothing else allocated,
     * so the arithmetic is easy to follow.
     *
     * @return array{0: User, 1: MonthlyPlan}
     */
    private function cycleWithAllowance(string $allowance, string $buffer = '0.00'): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '100000.00', 'cycle_start_day' => 25]);
        $user->categories()->where('name', 'Transport')->update([
            'monthly_budget' => $allowance,
            'is_allowance' => true,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);
        $plan->forceFill(['buffer' => $buffer])->save();
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        return [$user->fresh(), $plan->fresh(['weeklyBudgets', 'budgetCategories'])];
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
