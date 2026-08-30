<?php

namespace Tests\Feature;

use App\Enums\AdjustmentType;
use App\Models\Expense;
use App\Models\FinancialAlert;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Models\WeeklyBudget;
use App\Services\AlertService;
use App\Services\BudgetAdjustmentService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An alert is a statement about the present. Once the overspend it warns about
 * has been dealt with, leaving the banner up says something untrue and trains
 * the user to ignore the next one.
 */
class AlertsClearWhenResolvedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function covering_an_overspend_takes_the_banner_down(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('135.77');

        app(AlertService::class)->afterExpenseRecorded(Expense::query()->sole());
        $this->assertTrue($this->hasOverspendAlert($user), 'The overspend should raise one.');

        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::NextWeek, ['amount' => '135.77']);

        $this->assertFalse(
            $this->hasOverspendAlert($user->fresh()),
            'The week is no longer over, so the alert is no longer true.',
        );
    }

    #[Test]
    public function the_dashboard_stops_showing_it_too(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('135.77');

        app(AlertService::class)->afterExpenseRecorded(Expense::query()->sole());

        $before = $this->actingAs($user)->getJson('/api/dashboard')->json('data.alerts');
        $this->assertContains('Over your weekly budget', array_column($before, 'title'));

        $this->actingAs($user)->postJson("/api/weekly-budgets/{$week->id}/adjustments", [
            'type' => 'next_week',
            'amount' => '135.77',
        ])->assertOk();

        $after = $this->actingAs($user)->getJson('/api/dashboard')->json('data.alerts');
        $this->assertNotContains('Over your weekly budget', array_column($after, 'title'));
    }

    #[Test]
    public function a_week_still_over_keeps_its_alert(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('500.00');

        app(AlertService::class)->afterExpenseRecorded(Expense::query()->sole());

        // Only part of the overspend covered.
        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::NextWeek, ['amount' => '200.00']);

        $this->assertTrue(
            $this->hasOverspendAlert($user->fresh()),
            'Still 300 over, so the warning stands.',
        );
    }

    #[Test]
    public function deleting_the_expense_that_caused_it_also_clears_it(): void
    {
        [$user, $plan, $week] = $this->overspentWeek('135.77');

        $expense = Expense::query()->sole();
        app(AlertService::class)->afterExpenseRecorded($expense);

        $this->actingAs($user)->deleteJson("/api/expenses/{$expense->id}")->assertOk();

        $this->assertFalse($this->hasOverspendAlert($user->fresh()));
    }

    private function hasOverspendAlert(User $user): bool
    {
        return FinancialAlert::query()
            ->where('user_id', $user->id)
            ->where('type', 'budget_exceeded')
            ->visible()
            ->exists();
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
