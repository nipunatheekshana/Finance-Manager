<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A debt taken on part-way through a cycle. The plan is already running, so
 * the money has to come from something the user chooses — never silently out
 * of the day-to-day budget they are spending against.
 */
class AddDebtToLivePlanTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_debt_created_after_the_plan_is_listed_as_missing_from_it(): void
    {
        [$user, $plan] = $this->livePlan();
        $debt = $this->newDebt($user, '10000.00');

        $pending = $this->actingAs($user)
            ->getJson("/api/monthly-plans/{$plan->id}/pending-debts")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $pending);
        $this->assertSame($debt->id, $pending[0]['debt_id']);
        $this->assertSame('10000.00', $pending[0]['suggested_amount']);
    }

    #[Test]
    public function a_debt_already_in_the_plan_is_not_offered_again(): void
    {
        [$user, $plan] = $this->livePlan();

        $pending = $this->actingAs($user)
            ->getJson("/api/monthly-plans/{$plan->id}/pending-debts")
            ->json('data');

        $this->assertSame([], $pending, 'The seeded debt is already allocated.');
    }

    #[Test]
    public function every_way_of_paying_for_it_shows_its_own_effect(): void
    {
        [$user, $plan] = $this->livePlan();
        $debt = $this->newDebt($user, '10000.00');

        $options = $this->actingAs($user)
            ->getJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}/options?amount=10000.00")
            ->assertOk()
            ->json('data.options');

        $bySource = collect($options)->keyBy('source');

        $this->assertSame(
            Money::sub($plan->spending_budget, '10000.00'),
            $bySource['spending']['resulting_spending_budget'],
        );
        $this->assertSame(
            Money::sub($plan->buffer, '10000.00'),
            $bySource['buffer']['resulting_buffer'],
        );
        $this->assertTrue($bySource['savings']['available']);
    }

    #[Test]
    public function taking_it_from_day_to_day_money_shrinks_the_remaining_weeks(): void
    {
        [$user, $plan] = $this->livePlan();
        $debt = $this->newDebt($user, '10000.00');

        $this->add($user, $plan, $debt, '10000.00', 'spending');

        $plan->refresh();

        $this->assertSame('10000.00', $this->allocationFor($plan, $debt));
        $this->assertSame(Money::sub('64000.00', '10000.00'), $plan->spending_budget);

        // Week 1 is under way, so the reduction lands on what is left.
        $weeks = $plan->weeklyBudgets()->orderBy('week_number')->get();
        $this->assertSame(
            $plan->spending_budget,
            Money::sum($weeks->map(fn ($week) => $week->effectiveBudget())),
        );
    }

    #[Test]
    public function taking_it_from_the_buffer_leaves_the_weekly_budgets_alone(): void
    {
        [$user, $plan] = $this->livePlan();
        $debt = $this->newDebt($user, '10000.00');

        $before = Money::sum($plan->weeklyBudgets->map(fn ($week) => $week->effectiveBudget()));

        $this->add($user, $plan, $debt, '10000.00', 'buffer');

        $plan->refresh();

        // 16,000 of buffer less the 10,000 it just paid for.
        $this->assertSame('6000.00', $plan->buffer, 'The buffer gave up the money.');
        $this->assertSame($before, Money::sum(
            $plan->weeklyBudgets()->get()->map(fn ($week) => $week->effectiveBudget())
        ));
    }

    #[Test]
    public function taking_it_from_savings_reduces_the_goal_not_the_spending(): void
    {
        [$user, $plan] = $this->livePlan();
        $debt = $this->newDebt($user, '10000.00');

        $spendingBefore = $plan->spending_budget;

        $this->add($user, $plan, $debt, '10000.00', 'savings');

        $plan->refresh();

        $this->assertSame(Money::sub('20000.00', '10000.00'), $plan->savings);
        $this->assertSame($spendingBefore, $plan->spending_budget);
    }

    #[Test]
    public function it_never_takes_back_money_already_paid(): void
    {
        [$user, $plan] = $this->livePlan();

        // The whole savings allocation is already in the goal.
        $plan->savingsAllocations()->update(['saved_amount' => '20000.00']);

        $debt = $this->newDebt($user, '10000.00');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}", [
                'amount' => '10000.00',
                'source' => 'savings',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function the_plan_cannot_be_pushed_into_deficit_without_saying_so(): void
    {
        [$user, $plan] = $this->livePlan();
        $debt = $this->newDebt($user, '999000.00');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}", [
                'amount' => '999000.00',
                'source' => 'spending',
            ])
            ->assertStatus(422);
    }

    private function add(User $user, MonthlyPlan $plan, Debt $debt, string $amount, string $source): void
    {
        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}", [
                'amount' => $amount,
                'source' => $source,
            ])
            ->assertOk();
    }

    private function allocationFor(MonthlyPlan $plan, Debt $debt): string
    {
        return Money::of(
            $plan->debtAllocations()->where('debt_id', $debt->id)->value('planned_amount')
        );
    }

    private function newDebt(User $user, string $payment): Debt
    {
        return Debt::create([
            'user_id' => $user->id,
            'name' => 'New loan',
            'type' => 'loan',
            'original_amount' => '400000.00',
            'current_balance' => '400000.00',
            'minimum_payment' => $payment,
            'planned_payment' => $payment,
            'interest_rate' => '18.00',
            'due_day' => 10,
        ]);
    }

    /** A live plan: 200,000 income, 100,000 of debt, 20,000 savings, 16,000 buffer. */
    private function livePlan(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        Debt::create([
            'user_id' => $user->id,
            'name' => 'Visa',
            'type' => 'credit_card',
            'original_amount' => '300000.00',
            'current_balance' => '300000.00',
            'minimum_payment' => '10000.00',
            'planned_payment' => '100000.00',
            'interest_rate' => '24.00',
            'due_day' => 15,
        ]);

        SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency fund',
            'target_amount' => '500000.00',
            'current_amount' => '0.00',
            'monthly_target' => '20000.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '20000.00',
            'priority' => 1,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $plan->forceFill(['buffer' => '16000.00'])->save();
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        return [$user->fresh(), $plan->fresh(['weeklyBudgets', 'savingsAllocations'])];
    }
}
