<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\BudgetCalculationService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An allowance that has run out. The spending has already happened, so the
 * question is only where the money is taken from — and whichever pot gives it
 * up has to end up smaller by exactly the amount the allowance grew.
 */
class TopUpAllowanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_exhausted_allowance_is_listed_with_what_it_is_short(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();

        // 5,000 of fuel against a 4,000 pot.
        $this->spend($user, 'Transport', '5000.00');

        $short = $this->actingAs($user)
            ->getJson("/api/monthly-plans/{$plan->id}/allowance-top-ups")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $short);
        $this->assertSame('Transport', $short[0]['name']);
        $this->assertSame('1000.00', $short[0]['over_by']);
    }

    #[Test]
    public function topping_up_from_another_allowance_moves_it_between_the_two(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();
        $this->spend($user, 'Transport', '5000.00');

        $spendingBefore = $plan->spending_budget;

        $this->topUp($user, $plan, 'Transport', '1000.00', 'allowance', [
            'from_category_id' => $this->categoryId($user, 'Food'),
        ]);

        $this->assertSame('5000.00', $this->allowance($plan, $user, 'Transport'));
        $this->assertSame('9000.00', $this->allowance($plan, $user, 'Food'));

        // Nothing was created, so the weekly pool is untouched.
        $this->assertSame($spendingBefore, $plan->fresh()->spending_budget);
    }

    #[Test]
    public function the_topped_up_allowance_stops_charging_the_week(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();
        $this->spend($user, 'Transport', '5000.00');

        $budgets = app(BudgetCalculationService::class);

        // The 1,000 past the pot is on the week until it is covered.
        $this->assertSame('1000.00', $budgets->discretionarySpentBetween(
            $plan, $plan->cycle_start_date, $plan->cycle_end_date,
        ));

        $this->topUp($user, $plan, 'Transport', '1000.00', 'allowance', [
            'from_category_id' => $this->categoryId($user, 'Food'),
        ]);

        $this->assertSame('0.00', $budgets->discretionarySpentBetween(
            $plan->fresh(), $plan->cycle_start_date, $plan->cycle_end_date,
        ));
    }

    #[Test]
    public function taking_it_from_the_buffer_shrinks_the_buffer_by_the_same_amount(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();
        $this->spend($user, 'Transport', '5000.00');

        $this->topUp($user, $plan, 'Transport', '1000.00', 'buffer');

        $plan->refresh();

        $this->assertSame('5000.00', $this->allowance($plan, $user, 'Transport'));
        $this->assertSame('9000.00', $plan->buffer);
    }

    #[Test]
    public function taking_it_from_day_to_day_money_shrinks_the_spending_budget(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();
        $this->spend($user, 'Transport', '5000.00');

        $before = $plan->spending_budget;

        $this->topUp($user, $plan, 'Transport', '1000.00', 'spending');

        $this->assertSame(Money::sub($before, '1000.00'), $plan->fresh()->spending_budget);
    }

    #[Test]
    public function taking_it_from_savings_reduces_the_plans_savings(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();
        $this->spend($user, 'Transport', '5000.00');

        $this->topUp($user, $plan, 'Transport', '1000.00', 'savings');

        $this->assertSame('9000.00', $plan->fresh()->savings);
    }

    #[Test]
    public function an_allowance_cannot_give_up_money_it_has_already_spent(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();
        $this->spend($user, 'Transport', '5000.00');
        // Food has 10,000 reserved and 9,500 of it gone.
        $this->spend($user, 'Food', '9500.00');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/allowance-top-ups/".$this->categoryId($user, 'Transport'), [
                'amount' => '1000.00',
                'source' => 'allowance',
                'from_category_id' => $this->categoryId($user, 'Food'),
            ])
            ->assertStatus(422);

        $this->assertSame('10000.00', $this->allowance($plan, $user, 'Food'));
    }

    #[Test]
    public function an_allowance_cannot_be_topped_up_from_itself(): void
    {
        [$user, $plan] = $this->cycleWithAllowances();
        $this->spend($user, 'Transport', '5000.00');

        $transport = $this->categoryId($user, 'Transport');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/allowance-top-ups/{$transport}", [
                'amount' => '1000.00',
                'source' => 'allowance',
                'from_category_id' => $transport,
            ])
            ->assertStatus(422);
    }

    private function topUp(
        User $user,
        MonthlyPlan $plan,
        string $category,
        string $amount,
        string $source,
        array $extra = [],
    ): void {
        $this->actingAs($user)
            ->postJson(
                "/api/monthly-plans/{$plan->id}/allowance-top-ups/".$this->categoryId($user, $category),
                ['amount' => $amount, 'source' => $source] + $extra,
            )
            ->assertOk();
    }

    private function allowance(MonthlyPlan $plan, User $user, string $category): string
    {
        return Money::of(
            $plan->fresh()->budgetCategories()
                ->where('category_id', $this->categoryId($user, $category))
                ->value('budget_amount')
        );
    }

    private function spend(User $user, string $category, string $amount): void
    {
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, $category),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $amount,
            'expense_date' => '2026-08-28',
        ]);
    }

    /** Transport 4,000 · Food 10,000 · savings 10,000 · buffer 10,000. */
    private function cycleWithAllowances(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $user->categories()->where('name', 'Transport')
            ->update(['monthly_budget' => '4000.00', 'is_allowance' => true]);
        $user->categories()->where('name', 'Food')
            ->update(['monthly_budget' => '10000.00', 'is_allowance' => true]);

        SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency fund',
            'target_amount' => '500000.00',
            'current_amount' => '0.00',
            'monthly_target' => '10000.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '10000.00',
            'priority' => 1,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $plan->forceFill(['buffer' => '10000.00'])->save();
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        return [$user->fresh(), $plan->fresh(['budgetCategories', 'weeklyBudgets'])];
    }
}
