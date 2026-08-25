<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OverspendAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-10-05');
    }

    #[Test]
    public function an_overspent_week_offers_the_four_choices(): void
    {
        [$user, $plan] = $this->overspentPlan();
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);

        $response = $this->actingAs($user)
            ->getJson("/api/weekly-budgets/{$week->id}/adjustment-options")
            ->assertOk();

        $this->assertTrue($response->json('data.is_over_budget'));
        $this->assertSame('2500.00', $response->json('data.over_by'));

        $types = collect($response->json('data.options'))->pluck('type')->all();
        $this->assertSame(['next_week', 'buffer', 'category', 'ignore'], $types);
    }

    #[Test]
    public function nothing_changes_until_the_user_picks_an_option(): void
    {
        [$user, $plan] = $this->overspentPlan();
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $nextWeek = $plan->weeklyBudgets->firstWhere('week_number', 2);

        $this->actingAs($user)->getJson("/api/weekly-budgets/{$week->id}/adjustment-options")->assertOk();

        // Simply looking at the options must not move any money.
        $this->assertNull($nextWeek->fresh()->adjusted_amount);
        $this->assertSame('0.00', $plan->fresh()->buffer_used);
        $this->assertSame(0, $plan->adjustments()->count());
    }

    #[Test]
    public function choosing_to_adjust_next_week_reduces_only_that_week(): void
    {
        [$user, $plan] = $this->overspentPlan();
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $nextWeek = $plan->weeklyBudgets->firstWhere('week_number', 2);

        $originalNext = $nextWeek->budget_amount;

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", ['type' => 'next_week'])
            ->assertOk();

        $nextWeek->refresh();
        $this->assertSame(
            \App\Support\Money::sub($originalNext, '2500.00'),
            $nextWeek->adjusted_amount,
        );

        // The original figure is preserved alongside the adjustment.
        $this->assertSame($originalNext, $nextWeek->budget_amount);
    }

    #[Test]
    public function choosing_the_buffer_tops_up_the_week_and_draws_the_buffer_down(): void
    {
        [$user, $plan] = $this->overspentPlan(buffer: '20000.00');
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $originalBudget = $week->budget_amount;

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", ['type' => 'buffer'])
            ->assertOk();

        $this->assertSame(
            \App\Support\Money::add($originalBudget, '2500.00'),
            $week->fresh()->adjusted_amount,
        );
        $this->assertSame('2500.00', $plan->fresh()->buffer_used);
        $this->assertSame('17500.00', $plan->fresh()->bufferRemaining());
    }

    #[Test]
    public function the_buffer_option_is_unavailable_once_it_is_exhausted(): void
    {
        [$user, $plan] = $this->overspentPlan(buffer: '0.00');
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);

        $options = $this->actingAs($user)
            ->getJson("/api/weekly-budgets/{$week->id}/adjustment-options")
            ->json('data.options');

        $buffer = collect($options)->firstWhere('type', 'buffer');
        $this->assertFalse($buffer['available']);

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", ['type' => 'buffer'])
            ->assertStatus(422);
    }

    #[Test]
    public function choosing_a_category_reduces_that_categorys_budget(): void
    {
        [$user, $plan] = $this->overspentPlan();
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);

        $user->categories()->where('name', 'Entertainment')->update(['monthly_budget' => '8000.00']);
        $categoryId = $this->categoryId($user, 'Entertainment');

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", [
                'type' => 'category',
                'category_id' => $categoryId,
            ])
            ->assertOk();

        $this->assertDatabaseHas('budget_categories', [
            'monthly_plan_id' => $plan->id,
            'category_id' => $categoryId,
            'budget_amount' => '5500.00',
        ]);
    }

    #[Test]
    public function choosing_a_category_requires_naming_one(): void
    {
        [$user, $plan] = $this->overspentPlan();
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", ['type' => 'category'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    #[Test]
    public function choosing_to_ignore_records_the_decision_without_moving_money(): void
    {
        [$user, $plan] = $this->overspentPlan();
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);
        $nextWeek = $plan->weeklyBudgets->firstWhere('week_number', 2);

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", ['type' => 'ignore'])
            ->assertOk();

        $this->assertNull($nextWeek->fresh()->adjusted_amount);
        $this->assertSame('0.00', $plan->fresh()->buffer_used);

        $this->assertDatabaseHas('budget_adjustments', [
            'monthly_plan_id' => $plan->id,
            'type' => 'ignore',
        ]);
    }

    #[Test]
    public function every_applied_adjustment_is_recorded(): void
    {
        [$user, $plan] = $this->overspentPlan();
        $week = $plan->weeklyBudgets->firstWhere('week_number', 1);

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$week->id}/adjustments", ['type' => 'next_week'])
            ->assertOk();

        $this->assertDatabaseHas('budget_adjustments', [
            'user_id' => $user->id,
            'monthly_plan_id' => $plan->id,
            'source_weekly_budget_id' => $week->id,
            'type' => 'next_week',
            'amount' => '2500.00',
        ]);
    }

    #[Test]
    public function a_user_cannot_adjust_another_users_week(): void
    {
        [$user] = $this->overspentPlan();
        [$other, $otherPlan] = $this->overspentPlan();
        $theirWeek = $otherPlan->weeklyBudgets->firstWhere('week_number', 1);

        $this->actingAs($user)
            ->getJson("/api/weekly-budgets/{$theirWeek->id}/adjustment-options")
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$theirWeek->id}/adjustments", ['type' => 'ignore'])
            ->assertForbidden();
    }

    /**
     * A finalised plan whose first week has a 16,000 budget and 18,500 spent,
     * leaving it 2,500 over.
     *
     * @return array{0: User, 1: MonthlyPlan}
     */
    private function overspentPlan(string $buffer = '20000.00'): array
    {
        $user = $this->makeUser(['base_salary' => '280000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user, 2026, 9);

        $plan->forceFill(['buffer' => $buffer])->save();
        $planner->recordActualIncome($plan->fresh(), '80000.00', applySplit: false);

        $planner->applyWeeklyBudgets($plan->fresh(), [
            ['week_number' => 1, 'budget_amount' => '16000.00'],
            ['week_number' => 2, 'budget_amount' => '16000.00'],
            ['week_number' => 3, 'budget_amount' => '16000.00'],
            ['week_number' => 4, 'budget_amount' => '12000.00'],
        ]);

        $planner->finalize($plan->fresh());

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => '18500.00',
            'expense_date' => '2026-09-26',
        ]);

        return [$user->fresh(), $plan->fresh(['weeklyBudgets'])];
    }
}
