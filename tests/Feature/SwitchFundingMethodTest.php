<?php

namespace Tests\Feature;

use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A freelancer who set up "a steady amount I pay myself" and then decided to
 * budget only what has actually arrived. The old draw must not linger.
 */
class SwitchFundingMethodTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function moving_off_a_draw_clears_the_draw_figure(): void
    {
        $user = $this->selfEmployedOnADraw('5000.00');

        $this->switchTo($user, 'actual');

        $profile = $user->fresh()->financialProfile;

        $this->assertSame('actual', $profile->funding_method->value);
        $this->assertSame('0.00', $profile->base_salary);
        $this->assertSame(
            '0.00',
            $profile->target_draw,
            'Budgeting only what arrives cannot keep quoting a draw.',
        );
    }

    #[Test]
    public function a_draft_plan_stops_expecting_the_old_draw(): void
    {
        $user = $this->selfEmployedOnADraw('5000.00');

        $plan = app(FinancialPlanService::class)->draftFor($user->fresh(), 2026, 8);
        $this->assertSame('5000.00', $plan->expected_income);

        $this->switchTo($user, 'actual');

        // Nothing has arrived, so the plan expects nothing.
        $this->assertSame('0.00', $plan->fresh()->expected_income);
    }

    #[Test]
    public function a_finalised_plan_keeps_the_figure_it_was_built_on(): void
    {
        $user = $this->selfEmployedOnADraw('5000.00');

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->switchTo($user, 'actual');

        // Rule one of switching: live and finished cycles are never rewritten.
        $this->assertSame('5000.00', $plan->fresh()->expected_income);
    }

    #[Test]
    public function switching_to_a_draw_still_takes_the_figure_the_user_gives(): void
    {
        $user = $this->selfEmployedOnADraw('5000.00');
        $this->switchTo($user, 'actual');

        // Back to a draw, with a new amount.
        $this->actingAs($user)->putJson('/api/income-modes', [
            'income_mode' => 'self_employed',
            'funding_method' => 'draw',
            'target_draw' => '8000.00',
        ])->assertOk();

        $this->assertSame('8000.00', $user->fresh()->financialProfile->target_draw);
    }

    private function switchTo(\App\Models\User $user, string $funding): void
    {
        $this->actingAs($user)->putJson('/api/income-modes', [
            'income_mode' => 'self_employed',
            'funding_method' => $funding,
        ])->assertOk();
    }

    private function selfEmployedOnADraw(string $draw): \App\Models\User
    {
        $this->freezeOn('2026-08-01');

        $user = $this->makeUser(['cycle_start_day' => 1]);

        $this->actingAs($user)->putJson('/api/income-modes', [
            'income_mode' => 'self_employed',
            'funding_method' => 'draw',
            'target_draw' => $draw,
        ])->assertOk();

        $this->assertSame($draw, $user->fresh()->financialProfile->target_draw);

        return $user->fresh();
    }
}
