<?php

namespace Tests\Feature;

use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adding an allowance to a cycle already under way is the ordinary case —
 * you notice in week two that fuel needs its own pot. That means reopening a
 * live plan has to actually unlock it.
 */
class ReopenActivePlanTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reopening_a_live_plan_unlocks_it_for_editing(): void
    {
        [$user, $plan] = $this->activePlan();

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/reopen")
            ->assertOk();

        // 'active' would leave every step of the planner disabled.
        $this->assertSame('draft', $plan->fresh()->status->value);
    }

    #[Test]
    public function an_allowance_can_be_added_to_a_cycle_already_under_way(): void
    {
        [$user, $plan] = $this->activePlan();

        $budgetBefore = $plan->spending_budget;

        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/reopen")->assertOk();

        $this->actingAs($user)->putJson("/api/monthly-plans/{$plan->id}/allowances", [
            'allowances' => [
                ['category_id' => $this->categoryId($user, 'Transport'), 'amount' => '20000.00'],
            ],
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/finalize")->assertOk();

        $plan->refresh();
        $this->assertSame('active', $plan->status->value);
        $this->assertSame('20000.00', $plan->allowances);
        $this->assertSame(
            \App\Support\Money::sub($budgetBefore, '20000.00'),
            $plan->spending_budget,
            'The allowance has to come out of the weekly pool.',
        );
    }

    #[Test]
    public function the_weekly_budgets_are_recut_when_the_spending_budget_changes(): void
    {
        [$user, $plan] = $this->activePlan();

        $weeklyTotalBefore = \App\Support\Money::sum($plan->weeklyBudgets()->pluck('budget_amount'));
        $this->assertSame($plan->spending_budget, $weeklyTotalBefore);

        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/reopen")->assertOk();
        $this->actingAs($user)->putJson("/api/monthly-plans/{$plan->id}/allowances", [
            'allowances' => [
                ['category_id' => $this->categoryId($user, 'Transport'), 'amount' => '20000.00'],
            ],
        ])->assertOk();
        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/finalize")->assertOk();

        $plan->refresh();
        $weeklyTotalAfter = \App\Support\Money::sum($plan->weeklyBudgets()->pluck('budget_amount'));

        // Stale weeks would still hand out the old, larger figure every week.
        $this->assertSame($plan->spending_budget, $weeklyTotalAfter);
    }

    /** @return array{0: User, 1: MonthlyPlan} */
    private function activePlan(): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        return [$user->fresh(), $plan->fresh()];
    }
}
