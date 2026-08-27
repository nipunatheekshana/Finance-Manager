<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\BudgetCalculationService;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Turning a category into an allowance part-way through a cycle, when money
 * has already been spent in it. The spending does not disappear and is not
 * counted twice: it simply starts being drawn from the new pot.
 */
class AllowanceAppliedMidCycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function spending_already_recorded_is_drawn_from_the_new_allowance(): void
    {
        [$user, $plan] = $this->cycleWithSpending('5500.00');
        $budgets = app(BudgetCalculationService::class);

        // Before: the expense comes out of the weekly pool.
        $this->assertSame('5500.00', $budgets->discretionarySpentBetween(
            $plan, $plan->cycle_start_date, $plan->cycle_end_date,
        ));

        $this->makeAllowance($user, $plan, '27500.00');

        $plan->refresh();

        // After: the same expense is covered by the pot instead.
        $this->assertSame('0.00', $budgets->discretionarySpentBetween(
            $plan, $plan->cycle_start_date, $plan->cycle_end_date,
        ));

        $allowance = $budgets->allowanceSummaries($plan)[0];
        $this->assertSame('27500.00', $allowance['allocated']);
        $this->assertSame('5500.00', $allowance['spent'], 'The earlier expense counts against the pot.');
        $this->assertSame('22000.00', $allowance['remaining']);
    }

    #[Test]
    public function the_expense_record_itself_is_untouched(): void
    {
        [$user, $plan] = $this->cycleWithSpending('5500.00');

        $before = Expense::query()->sole();
        $this->makeAllowance($user, $plan, '27500.00');
        $after = Expense::query()->sole();

        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->amount, $after->amount);
        $this->assertSame($before->expense_date->toDateString(), $after->expense_date->toDateString());
    }

    #[Test]
    public function the_week_it_was_spent_in_is_no_longer_charged_for_it(): void
    {
        [$user, $plan] = $this->cycleWithSpending('5500.00');
        $budgets = app(BudgetCalculationService::class);

        $weekBefore = $budgets->weeklySummaries($plan)[0];
        $this->assertSame('5500.00', $weekBefore['spent']);

        $this->makeAllowance($user, $plan, '27500.00');

        $weekAfter = $budgets->weeklySummaries($plan->fresh())[0];
        $this->assertSame('0.00', $weekAfter['spent']);

        // The week is smaller now, so it must not be over on day one.
        $this->assertSame('safe', $weekAfter['status']);
    }

    #[Test]
    public function spending_past_the_allowance_still_reaches_the_week(): void
    {
        // More already spent than the allowance will reserve.
        [$user, $plan] = $this->cycleWithSpending('8000.00');

        $this->makeAllowance($user, $plan, '5000.00');

        $budgets = app(BudgetCalculationService::class);

        // 5,000 covered by the pot, 3,000 over it and back on the week.
        $this->assertSame('3000.00', $budgets->discretionarySpentBetween(
            $plan->fresh(), $plan->cycle_start_date, $plan->cycle_end_date,
        ));
    }

    /** Reopen, reserve the category, finalise again. */
    private function makeAllowance(User $user, MonthlyPlan $plan, string $amount): void
    {
        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/reopen")->assertOk();

        $this->actingAs($user)->putJson("/api/monthly-plans/{$plan->id}/allowances", [
            'allowances' => [
                ['category_id' => $this->categoryId($user, 'Entertainment'), 'amount' => $amount],
            ],
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/finalize")->assertOk();
    }

    /** @return array{0: User, 1: MonthlyPlan} */
    private function cycleWithSpending(string $amount): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-27');

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Entertainment'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $amount,
            'expense_date' => '2026-08-27',
        ]);

        return [$user->fresh(), $plan->fresh()];
    }
}
