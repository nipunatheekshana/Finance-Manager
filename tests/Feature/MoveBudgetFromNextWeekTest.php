<?php

namespace Tests\Feature;

use App\Enums\AdjustmentType;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Models\WeeklyBudget;
use App\Services\BudgetAdjustmentService;
use App\Services\BudgetCalculationService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Take it from next week" has to actually move the money. Reducing next week
 * without crediting this one leaves the user still over budget, having given
 * up next week's money for nothing.
 */
class MoveBudgetFromNextWeekTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_overspent_week_is_credited_with_what_next_week_gives_up(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('3000.00');

        $before = $week->effectiveBudget();

        $this->apply($week, '3000.00');

        $this->assertSame(
            Money::add($before, '3000.00'),
            $week->fresh()->effectiveBudget(),
            'The week that went over has to receive the money.',
        );
    }

    #[Test]
    public function the_week_is_no_longer_reported_as_over(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('3000.00');

        $this->apply($week, '3000.00');

        $summary = app(BudgetCalculationService::class)->weeklySummary($week->fresh());

        $this->assertSame('0.00', $summary['over_by']);
        $this->assertNotSame('over', $summary['status'], 'Covering the overspend has to clear it.');
    }

    #[Test]
    public function the_cycle_total_is_unchanged_because_money_only_moved(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('3000.00');

        $totalBefore = $this->weeklyTotal($plan);

        $this->apply($week, '3000.00');

        $this->assertSame($totalBefore, $this->weeklyTotal($plan->fresh()));
    }

    #[Test]
    public function it_moves_only_what_the_next_week_actually_has(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('3000.00');

        $next = $plan->weeklyBudgets()->where('week_number', 2)->sole();
        $next->forceFill(['adjusted_amount' => '1200.00'])->save();

        $before = $week->effectiveBudget();

        $adjustment = $this->apply($week, '3000.00');

        // Next week only had 1,200 to give, so that is what moved — not the
        // 3,000 that was asked for.
        $this->assertSame('1200.00', Money::of($adjustment->amount));
        $this->assertSame(Money::add($before, '1200.00'), $week->fresh()->effectiveBudget());
        $this->assertSame('0.00', $next->fresh()->effectiveBudget());
    }

    private function apply(WeeklyBudget $week, string $amount)
    {
        return app(BudgetAdjustmentService::class)->apply(
            $week,
            AdjustmentType::NextWeek,
            ['amount' => $amount],
        );
    }

    private function weeklyTotal(MonthlyPlan $plan): string
    {
        return Money::sum(
            $plan->weeklyBudgets()->get()->map(fn (WeeklyBudget $week) => $week->effectiveBudget())
        );
    }

    /** @return array{0: User, 1: MonthlyPlan, 2: WeeklyBudget} */
    private function overspentWeek(string $overBy): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $week = $plan->weeklyBudgets()->where('week_number', 1)->sole();

        $this->freezeOn('2026-08-28');

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => Money::add($week->effectiveBudget(), $overBy),
            'expense_date' => '2026-08-28',
        ]);

        return [$user->fresh(), $plan->fresh(), $week->fresh()];
    }
}
