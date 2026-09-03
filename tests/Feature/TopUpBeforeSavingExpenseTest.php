<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\BudgetCalculationService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deciding where an allowance's excess comes from *as* the expense is saved,
 * so the pot is right the moment the spending lands and no banner has to fire.
 */
class TopUpBeforeSavingExpenseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_preview_names_the_plan_so_the_sheet_can_offer_the_choice(): void
    {
        [$user, $plan] = $this->cycle();

        $preview = $this->actingAs($user)->postJson('/api/expenses/preview', [
            'amount' => '13000.00',
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Transport'),
        ])->assertOk()->json('data');

        $this->assertSame($plan->id, $preview['plan_id']);
        $this->assertSame('9000.00', $preview['allowance']['from_day_to_day']);
    }

    #[Test]
    public function the_pot_grows_as_the_expense_is_saved_and_the_week_is_never_charged(): void
    {
        [$user, $plan] = $this->cycle();

        // 13,000 against a 4,000 pot: 9,000 over. Cover it from Food.
        $response = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '13000.00',
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Transport'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'allowance_top_up' => [
                'source' => 'allowance',
                'amount' => '9000.00',
                'from_category_id' => $this->categoryId($user, 'Food'),
            ],
        ])->assertCreated();

        $this->assertSame('13000.00', $this->pot($plan, $user, 'Transport'));
        $this->assertSame('11000.00', $this->pot($plan, $user, 'Food'));

        // Nothing landed on day-to-day money, so the week is not over and no
        // decision is put in front of the user.
        $this->assertFalse($response->json('week.is_over'));
        $this->assertSame('0.00', app(BudgetCalculationService::class)->discretionarySpentBetween(
            $plan->fresh(), $plan->cycle_start_date, $plan->cycle_end_date,
        ));
    }

    #[Test]
    public function without_a_choice_it_still_lands_on_the_week_as_before(): void
    {
        [$user, $plan] = $this->cycle();

        $response = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '13000.00',
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Transport'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
        ])->assertCreated();

        $this->assertSame('4000.00', $this->pot($plan, $user, 'Transport'));

        // The 9,000 past the pot is day-to-day spending now — the old rule,
        // still the default when the user decides nothing.
        $this->assertSame('9000.00', app(BudgetCalculationService::class)->discretionarySpentBetween(
            $plan->fresh(), $plan->cycle_start_date, $plan->cycle_end_date,
        ));
        $this->assertNotNull($response->json('week'));
    }

    #[Test]
    public function a_pot_that_cannot_cover_it_refuses_and_saves_nothing(): void
    {
        [$user, $plan] = $this->cycle();

        // Food has 20,000; asking for 25,000 from it is more than it holds.
        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '29000.00',
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Transport'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'allowance_top_up' => [
                'source' => 'allowance',
                'amount' => '25000.00',
                'from_category_id' => $this->categoryId($user, 'Food'),
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('allowance_top_up');

        // One transaction: the pots are untouched and the expense was not saved.
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame('20000.00', $this->pot($plan, $user, 'Food'));
        $this->assertSame('4000.00', $this->pot($plan, $user, 'Transport'));
    }

    #[Test]
    public function a_category_with_no_pot_cannot_be_topped_up(): void
    {
        [$user] = $this->cycle();

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '3000.00',
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'allowance_top_up' => ['source' => 'buffer', 'amount' => '3000.00'],
        ])->assertStatus(422);

        $this->assertSame(0, Expense::query()->count());
    }

    private function pot(MonthlyPlan $plan, User $user, string $category): string
    {
        return Money::of(
            $plan->fresh()->budgetCategories()
                ->where('category_id', $this->categoryId($user, $category))
                ->value('budget_amount')
        );
    }

    /** Transport 4,000 · Food 20,000 · buffer 10,000. */
    private function cycle(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);
        $user->categories()->where('name', 'Transport')->update(['monthly_budget' => '4000.00', 'is_allowance' => true]);
        $user->categories()->where('name', 'Food')->update(['monthly_budget' => '20000.00', 'is_allowance' => true]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $plan->forceFill(['buffer' => '10000.00'])->save();
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        return [$user->fresh(), $plan->fresh()];
    }
}
