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
 * Covering an overspend by reducing an allowance. Reserved money moves out of
 * the pot and into the week — and the plan has to still add up afterwards.
 *
 * A plain category budget is only a warning line: reducing it frees nothing,
 * so it is not offered as a way to pay for anything.
 */
class ReduceCategoryToCoverOverspendTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_overspent_week_receives_what_the_allowance_gives_up(): void
    {
        [$user, $plan, $week] = $this->overspentWeekWithAllowance('1000.00');

        $before = $week->effectiveBudget();

        $this->reduce($week, $user, 'Transport', '1000.00');

        $this->assertSame(
            Money::add($before, '1000.00'),
            $week->fresh()->effectiveBudget(),
            'Reducing a pot has to put the money somewhere.',
        );
    }

    #[Test]
    public function the_plan_still_adds_up_afterwards(): void
    {
        [$user, $plan, $week] = $this->overspentWeekWithAllowance('1000.00');

        $spendingBefore = $plan->spending_budget;

        $this->reduce($week, $user, 'Transport', '1000.00');

        $plan->refresh();

        // The stored total has to match the rows it is derived from, or every
        // figure built on it is wrong from here on.
        $this->assertSame(
            Money::sum($plan->budgetCategories()->where('is_allowance', true)->pluck('budget_amount')),
            Money::of($plan->allowances),
        );

        // Less reserved means more to spend.
        $this->assertSame(Money::add($spendingBefore, '1000.00'), $plan->spending_budget);
    }

    #[Test]
    public function the_week_is_no_longer_over(): void
    {
        [$user, $plan, $week] = $this->overspentWeekWithAllowance('1000.00');

        $this->reduce($week, $user, 'Transport', '1000.00');

        $summary = app(BudgetCalculationService::class)->weeklySummary($week->fresh());

        $this->assertSame('0.00', $summary['over_by']);
    }

    #[Test]
    public function an_allowance_cannot_give_up_money_it_has_already_spent(): void
    {
        [$user, $plan, $week] = $this->overspentWeekWithAllowance('1000.00');

        // 5,000 reserved for Transport, 4,800 of it already gone.
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Transport'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => '4800.00',
            'expense_date' => '2026-08-28',
        ]);

        $this->expectExceptionMessage('Transport');

        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::Category, [
            'amount' => '1000.00',
            'category_id' => $this->categoryId($user, 'Transport'),
        ]);
    }

    #[Test]
    public function a_warning_only_category_is_not_offered_as_a_source(): void
    {
        [$user, $plan, $week] = $this->overspentWeekWithAllowance('1000.00');

        // Shopping has a monthly budget but reserves nothing.
        $user->categories()->where('name', 'Shopping')->update(['monthly_budget' => '9000.00']);

        $options = app(BudgetAdjustmentService::class)->optionsFor($week->fresh());
        $category = collect($options['options'])->firstWhere('type', 'category');

        $names = array_column($category['candidates'] ?? [], 'name');

        $this->assertContains('Transport', $names, 'An allowance holds real money.');
        $this->assertNotContains('Shopping', $names, 'A warning line frees nothing.');
    }

    #[Test]
    public function reducing_a_warning_only_category_is_refused(): void
    {
        [$user, $plan, $week] = $this->overspentWeekWithAllowance('1000.00');

        $user->categories()->where('name', 'Shopping')->update(['monthly_budget' => '9000.00']);

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", [
                'type' => 'category',
                'amount' => '1000.00',
                'category_id' => $this->categoryId($user, 'Shopping'),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function the_week_says_when_an_allowance_spill_is_the_cause(): void
    {
        [$user, $plan, $week] = $this->overspentWeekWithAllowance('1000.00');

        // 5,000 reserved for Transport; 7,500 spent, so 2,500 landed on the week.
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Transport'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => '7500.00',
            'expense_date' => '2026-08-28',
        ]);

        $options = app(BudgetAdjustmentService::class)->optionsFor($week->fresh());

        $this->assertSame($plan->id, $options['plan_id']);
        $this->assertCount(1, $options['spills']);
        $this->assertSame('Transport', $options['spills'][0]['name']);
        $this->assertSame('2500.00', $options['spills'][0]['spilled']);
    }

    private function reduce(WeeklyBudget $week, User $user, string $category, string $amount): void
    {
        app(BudgetAdjustmentService::class)->apply($week, AdjustmentType::Category, [
            'amount' => $amount,
            'category_id' => $this->categoryId($user, $category),
        ]);
    }

    /** @return array{0: User, 1: MonthlyPlan, 2: WeeklyBudget} */
    private function overspentWeekWithAllowance(string $overBy): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);
        $user->categories()->where('name', 'Transport')
            ->update(['monthly_budget' => '5000.00', 'is_allowance' => true]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $week = $plan->weeklyBudgets()->where('week_number', 1)->sole();

        $this->freezeOn('2026-08-28');

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Entertainment'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => Money::add($week->effectiveBudget(), $overBy),
            'expense_date' => '2026-08-28',
        ]);

        return [$user->fresh(), $plan->fresh(['budgetCategories']), $week->fresh()];
    }
}
