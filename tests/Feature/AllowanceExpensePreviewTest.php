<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Logging an expense against an allowance.
 *
 * The money was reserved when the plan was built, so it does not touch the
 * week — and warning about the weekly budget would be telling the user off for
 * spending exactly what they planned to spend.
 */
class AllowanceExpensePreviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function spending_inside_an_allowance_does_not_warn_about_the_week(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('20000.00');

        // Far more than a week's day-to-day budget, but the pot covers it.
        $preview = $this->preview($user, '15000.00', 'Transport');

        $this->assertFalse($preview['will_exceed_week'], 'The pot pays for this, not the week.');
        $this->assertFalse($preview['needs_decision']);
        $this->assertSame('0.00', $preview['week']['spent_after']);
    }

    #[Test]
    public function the_preview_says_which_allowance_pays_for_it(): void
    {
        [$user] = $this->cycleWithAllowance('20000.00');

        $preview = $this->preview($user, '15000.00', 'Transport');

        $this->assertNotNull($preview['allowance']);
        $this->assertSame('Transport', $preview['allowance']['name']);
        $this->assertSame('15000.00', $preview['allowance']['covered']);
        $this->assertSame('0.00', $preview['allowance']['from_day_to_day']);
        $this->assertSame('5000.00', $preview['allowance']['remaining_after']);
    }

    #[Test]
    public function only_the_part_past_the_allowance_reaches_the_week(): void
    {
        [$user] = $this->cycleWithAllowance('5000.00');

        // 5,000 of pot, so 3,000 of this comes from day-to-day money.
        $preview = $this->preview($user, '8000.00', 'Transport');

        $this->assertSame('5000.00', $preview['allowance']['covered']);
        $this->assertSame('3000.00', $preview['allowance']['from_day_to_day']);
        $this->assertSame('3000.00', $preview['week']['spent_after']);
    }

    #[Test]
    public function an_exhausted_allowance_puts_the_whole_expense_on_the_week(): void
    {
        [$user, $plan] = $this->cycleWithAllowance('5000.00');

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Transport'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => '5000.00',
            'expense_date' => '2026-08-26',
        ]);

        $preview = $this->preview($user, '2000.00', 'Transport');

        $this->assertSame('0.00', $preview['allowance']['remaining_before']);
        $this->assertSame('2000.00', $preview['allowance']['from_day_to_day']);
        $this->assertSame('2000.00', $preview['week']['spent_after']);
    }

    #[Test]
    public function an_ordinary_category_is_unaffected(): void
    {
        [$user] = $this->cycleWithAllowance('20000.00');

        $preview = $this->preview($user, '3000.00', 'Shopping');

        $this->assertNull($preview['allowance']);
        $this->assertSame('3000.00', $preview['week']['spent_after']);
    }

    /** @return array<string, mixed> */
    private function preview(User $user, string $amount, string $category): array
    {
        return $this->actingAs($user)->postJson('/api/expenses/preview', [
            'amount' => $amount,
            'expense_date' => '2026-08-27',
            'category_id' => $this->categoryId($user, $category),
        ])->assertOk()->json('data');
    }

    /** @return array{0: User, 1: MonthlyPlan} */
    private function cycleWithAllowance(string $amount): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);
        $user->categories()->where('name', 'Transport')->update([
            'monthly_budget' => $amount,
            'is_allowance' => true,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-27');

        return [$user->fresh(), $plan->fresh()];
    }
}
