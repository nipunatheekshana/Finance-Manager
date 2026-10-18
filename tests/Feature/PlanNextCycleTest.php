<?php

namespace Tests\Feature;

use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Planning the next cycle before it starts. The plan is built and finalised
 * early, waits as "scheduled" without disturbing the live one, and takes over
 * by itself on its first day.
 */
class PlanNextCycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_next_cycles_draft_can_be_opened_while_the_current_one_runs(): void
    {
        [$user] = $this->liveCycle();

        $plan = $this->actingAs($user)->getJson('/api/monthly-plans/next')->assertOk()->json('data');

        $this->assertSame('draft', $plan['status']);
        $this->assertSame('2026-09-25', $plan['cycle_start_date']);
        $this->assertSame('2026-10-24', $plan['cycle_end_date']);
    }

    #[Test]
    public function finalising_it_early_schedules_it_and_leaves_the_live_plan_alone(): void
    {
        [$user, $current] = $this->liveCycle();
        $next = $this->nextDraft($user);

        $this->actingAs($user)->postJson("/api/monthly-plans/{$next->id}/finalize")->assertOk();

        $this->assertSame('scheduled', $next->fresh()->status->value);
        $this->assertSame('active', $current->fresh()->status->value, 'The running cycle must not be closed early.');
        $this->assertGreaterThan(0, $next->fresh()->weeklyBudgets()->count(), 'Weeks are cut now, not on the day.');
    }

    #[Test]
    public function it_is_still_the_live_plan_that_the_app_runs_on_until_the_day_comes(): void
    {
        [$user, $current] = $this->liveCycle();
        $next = $this->nextDraft($user);
        $this->actingAs($user)->postJson("/api/monthly-plans/{$next->id}/finalize")->assertOk();

        $this->assertSame($current->id, app(FinancialPlanService::class)->activePlanFor($user)->id);
        $this->assertSame($current->id, $this->actingAs($user)->getJson('/api/monthly-plans/current')->json('data.id'));
    }

    #[Test]
    public function on_its_first_day_the_scheduled_plan_takes_over_and_the_old_one_is_completed(): void
    {
        [$user, $current] = $this->liveCycle();
        $next = $this->nextDraft($user);
        $this->actingAs($user)->postJson("/api/monthly-plans/{$next->id}/finalize")->assertOk();

        $this->freezeOn('2026-09-25');

        $live = app(FinancialPlanService::class)->activePlanFor($user->fresh());

        $this->assertSame($next->id, $live->id);
        $this->assertSame('active', $live->status->value);
        $this->assertSame('completed', $current->fresh()->status->value);
    }

    #[Test]
    public function a_scheduled_plan_can_be_reopened_for_changes(): void
    {
        [$user] = $this->liveCycle();
        $next = $this->nextDraft($user);
        $this->actingAs($user)->postJson("/api/monthly-plans/{$next->id}/finalize")->assertOk();

        $this->actingAs($user)->postJson("/api/monthly-plans/{$next->id}/reopen")->assertOk();

        $this->assertSame('draft', $next->fresh()->status->value);
    }

    #[Test]
    public function finalising_the_current_cycle_still_goes_live_at_once(): void
    {
        $this->freezeOn('2026-09-18');
        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->assertSame('active', $plan->fresh()->status->value);
    }

    #[Test]
    public function the_dashboard_says_whether_next_cycle_is_planned_yet(): void
    {
        [$user] = $this->liveCycle();

        $this->assertNull($this->actingAs($user)->getJson('/api/dashboard')->json('data.salary.next_plan_status'));

        $next = $this->nextDraft($user);
        $this->actingAs($user)->postJson("/api/monthly-plans/{$next->id}/finalize")->assertOk();

        $this->assertSame('scheduled', $this->actingAs($user)->getJson('/api/dashboard')->json('data.salary.next_plan_status'));
    }

    private function nextDraft(User $user): MonthlyPlan
    {
        $id = $this->actingAs($user)->getJson('/api/monthly-plans/next')->json('data.id');

        return MonthlyPlan::findOrFail($id);
    }

    /** Live cycle 25 Aug – 24 Sep, today 18 Sep. */
    private function liveCycle(): array
    {
        $this->freezeOn('2026-08-25');
        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-09-18');

        return [$user->fresh(), $plan->fresh()];
    }
}
