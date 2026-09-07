<?php

namespace Tests\Feature;

use App\Enums\AdjustmentType;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Models\WeeklyBudget;
use App\Services\BudgetAdjustmentService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A finished week with money left in it. That money is real and unspent; the
 * user decides whether next week gets it or a goal does.
 */
class CarryLeftoverForwardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function leftover_moves_into_the_next_week_and_the_cycle_total_is_unchanged(): void
    {
        [$user, $plan, $week] = $this->finishedWeekWithLeftover('1000.00');

        $next = $plan->weeklyBudgets()->where('week_number', 2)->sole();
        $weekBefore = $week->effectiveBudget();
        $nextBefore = $next->effectiveBudget();
        $totalBefore = $this->weeklyTotal($plan);

        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::CarryForward);

        $this->assertSame(Money::sub($weekBefore, '1000.00'), $week->fresh()->effectiveBudget());
        $this->assertSame(Money::add($nextBefore, '1000.00'), $next->fresh()->effectiveBudget());
        $this->assertSame($totalBefore, $this->weeklyTotal($plan->fresh()));
    }

    #[Test]
    public function a_week_still_running_cannot_be_carried_forward_yet(): void
    {
        [$user, $plan, $week] = $this->finishedWeekWithLeftover('1000.00');

        // Back inside week 1.
        $this->freezeOn('2026-08-30');

        $this->expectExceptionMessage('finished');
        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::CarryForward);
    }

    #[Test]
    public function more_than_the_leftover_is_refused(): void
    {
        [$user, $plan, $week] = $this->finishedWeekWithLeftover('1000.00');

        $this->expectExceptionMessage('1000.00');
        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::CarryForward, ['amount' => '5000.00']);
    }

    #[Test]
    public function leftover_put_towards_a_goal_is_a_real_deposit_and_the_plan_still_adds_up(): void
    {
        [$user, $plan, $week] = $this->finishedWeekWithLeftover('1000.00');
        $goal = SavingsGoal::query()->where('user_id', $user->id)->sole();

        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::Savings, [
            'savings_goal_id' => $goal->id,
        ]);

        $plan->refresh();

        $this->assertSame('1000.00', Money::of($goal->fresh()->current_amount));

        $allocation = $plan->savingsAllocations()->where('savings_goal_id', $goal->id)->sole();
        $this->assertSame('11000.00', Money::of($allocation->planned_amount));
        $this->assertSame('1000.00', Money::of($allocation->saved_amount));

        // The week gave the money up, savings took it, and the weeks still
        // divide exactly the (smaller) spending budget.
        $this->assertSame($plan->spending_budget, $this->weeklyTotal($plan));
        $this->assertSame('11000.00', $plan->savings);
    }

    #[Test]
    public function the_review_offers_the_choice_for_a_finished_week_with_money_left(): void
    {
        [$user, $plan, $week] = $this->finishedWeekWithLeftover('1000.00');

        $leftover = $this->actingAs($user)
            ->getJson("/api/weekly-budgets/{$week->id}/review")
            ->assertOk()
            ->json('leftover');

        $this->assertTrue($leftover['can_carry']);
        $this->assertTrue($leftover['can_save']);
        $this->assertSame('1000.00', $leftover['remaining']);
        $this->assertSame(2, $leftover['next_week_number']);
        $this->assertCount(1, $leftover['goals']);
    }

    private function weeklyTotal(MonthlyPlan $plan): string
    {
        return Money::sum($plan->weeklyBudgets()->get()->map(fn (WeeklyBudget $w) => $w->effectiveBudget()));
    }

    /** @return array{0: User, 1: MonthlyPlan, 2: WeeklyBudget} */
    private function finishedWeekWithLeftover(string $leftover): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

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
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $week = $plan->weeklyBudgets()->where('week_number', 1)->sole();

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => Money::sub($week->effectiveBudget(), $leftover),
            'expense_date' => '2026-08-28',
        ]);

        // Week 1 (25 Aug – 1 Sep) is over.
        $this->freezeOn('2026-09-02');

        return [$user->fresh(), $plan->fresh(), $week->fresh()];
    }
}
