<?php

namespace Tests\Feature;

use App\Models\BudgetAdjustment;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Repairing transfers made before the credit half of the move existed.
 */
class RepairWeekTransfersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_credits_a_week_that_gave_up_budget_and_never_received_it(): void
    {
        [$plan, $adjustment] = $this->damagedTransfer('135.77');

        $source = $adjustment->sourceWeeklyBudget;
        $this->assertNull($source->adjusted_amount, 'The week was never credited.');

        $this->artisan('finance:repair-week-transfers')->assertSuccessful();
        $this->assertNull($source->fresh()->adjusted_amount, 'A dry run changes nothing.');

        $this->artisan('finance:repair-week-transfers --force')->assertSuccessful();

        $this->assertSame(
            Money::add($source->budget_amount, '135.77'),
            Money::of($source->fresh()->adjusted_amount),
        );
    }

    #[Test]
    public function a_week_adjusted_since_is_left_alone(): void
    {
        [$plan, $adjustment] = $this->damagedTransfer('135.77');

        // Changed for some other reason in the meantime.
        $adjustment->sourceWeeklyBudget->forceFill(['adjusted_amount' => '9000.00'])->save();

        $this->artisan('finance:repair-week-transfers --force')->assertSuccessful();

        $this->assertSame(
            '9000.00',
            Money::of($adjustment->sourceWeeklyBudget->fresh()->adjusted_amount),
            'Crediting it now could double the transfer.',
        );
    }

    #[Test]
    public function it_reports_nothing_when_there_is_nothing_to_repair(): void
    {
        $this->artisan('finance:repair-week-transfers')
            ->expectsOutputToContain('Nothing to repair.')
            ->assertSuccessful();
    }

    /** @return array{0: MonthlyPlan, 1: BudgetAdjustment} */
    private function damagedTransfer(string $amount): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $weekOne = $plan->weeklyBudgets()->where('week_number', 1)->sole();
        $weekTwo = $plan->weeklyBudgets()->where('week_number', 2)->sole();

        // Exactly what the old code left behind: week two reduced, week one
        // untouched.
        $weekTwo->forceFill([
            'adjusted_amount' => Money::sub($weekTwo->budget_amount, $amount),
        ])->save();

        $adjustment = BudgetAdjustment::create([
            'user_id' => $user->id,
            'monthly_plan_id' => $plan->id,
            'weekly_budget_id' => $weekTwo->id,
            'source_weekly_budget_id' => $weekOne->id,
            'type' => 'next_week',
            'amount' => $amount,
            'original_amount' => $weekTwo->budget_amount,
            'adjusted_amount' => Money::sub($weekTwo->budget_amount, $amount),
            'reason' => 'Overspend in week 1',
        ]);

        return [$plan->fresh(), $adjustment->fresh()];
    }
}
