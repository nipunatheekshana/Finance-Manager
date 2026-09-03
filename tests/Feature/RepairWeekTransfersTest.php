<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BudgetAdjustment;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Repairing transfers made before the credit half of the move existed. The
 * trail decides what happened; the command never guesses from the figures.
 */
class RepairWeekTransfersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_credits_a_week_that_gave_up_budget_and_never_received_it(): void
    {
        [$plan, $adjustment] = $this->damagedTransfer('135.77');
        $source = $adjustment->sourceWeeklyBudget;

        $this->artisan('finance:repair-week-transfers')->assertSuccessful();
        $this->assertNull($source->fresh()->adjusted_amount, 'A dry run changes nothing.');

        $this->artisan('finance:repair-week-transfers --force')->assertSuccessful();

        $this->assertSame(
            Money::add($source->budget_amount, '135.77'),
            Money::of($source->fresh()->adjusted_amount),
        );
    }

    #[Test]
    public function a_week_already_adjusted_for_other_reasons_is_still_credited(): void
    {
        // The earlier guard skipped any week with an adjusted_amount, which is
        // exactly the week a second overspend lands on.
        [$plan, $adjustment] = $this->damagedTransfer('3881.55', type: 'category');
        $source = $adjustment->sourceWeeklyBudget;
        $source->forceFill(['adjusted_amount' => '3218.45'])->save();

        $this->artisan('finance:repair-week-transfers --force')->assertSuccessful();

        $this->assertSame('7100.00', Money::of($source->fresh()->adjusted_amount));
    }

    #[Test]
    public function running_it_twice_credits_once(): void
    {
        [$plan, $adjustment] = $this->damagedTransfer('135.77');
        $source = $adjustment->sourceWeeklyBudget;

        $this->artisan('finance:repair-week-transfers --force')->assertSuccessful();
        $this->artisan('finance:repair-week-transfers --force')
            ->expectsOutputToContain('already repaired')
            ->assertSuccessful();

        $this->assertSame(Money::add($source->budget_amount, '135.77'), Money::of($source->fresh()->adjusted_amount));
    }

    #[Test]
    public function an_adjustment_the_fixed_code_made_is_left_alone(): void
    {
        [$plan, $adjustment] = $this->damagedTransfer('1000.00');

        // What the fixed code writes beside every adjustment.
        $log = AuditLog::create([
            'user_id' => $adjustment->user_id,
            'action' => 'budget.week_adjusted',
            'auditable_type' => \App\Models\WeeklyBudget::class,
            'auditable_id' => $adjustment->weekly_budget_id,
            'old_values' => ['budget' => '4354.22', 'source_week_budget' => '4490.00'],
            'new_values' => ['budget' => '3354.22', 'source_week_budget' => '5490.00'],
        ]);
        $log->forceFill(['created_at' => $adjustment->created_at])->save();

        $this->artisan('finance:repair-week-transfers --force')
            ->expectsOutputToContain('credited at the time')
            ->assertSuccessful();

        $this->assertNull($adjustment->sourceWeeklyBudget->fresh()->adjusted_amount);
    }

    #[Test]
    public function it_re_totals_the_plan_after_an_allowance_adjustment(): void
    {
        [$plan, $adjustment] = $this->damagedTransfer('500.00', type: 'category');

        $plan->forceFill(['allowances' => '5000.00'])->save();

        $this->artisan('finance:repair-week-transfers --force')->assertSuccessful();

        $this->assertSame('4500.00', Money::of($plan->fresh()->allowances));
    }

    #[Test]
    public function an_adjustment_can_be_reversed_instead(): void
    {
        [$plan, $adjustment] = $this->damagedTransfer('3881.55', type: 'category');

        $this->artisan("finance:repair-week-transfers --reverse --adjustment={$adjustment->id} --force")
            ->assertSuccessful();

        // The allowance has its money back and the week was never touched.
        $row = $plan->budgetCategories()->where('category_id', $adjustment->category_id)->sole();
        $this->assertSame('5000.00', Money::of($row->budget_amount));
        $this->assertNull($adjustment->sourceWeeklyBudget->fresh()->adjusted_amount);
        $this->assertSame('5000.00', Money::of($plan->fresh()->allowances));

        // And it cannot then be "repaired" on top.
        $this->artisan('finance:repair-week-transfers --force')
            ->expectsOutputToContain('reversed')
            ->assertSuccessful();
    }

    /** @return array{0: MonthlyPlan, 1: BudgetAdjustment} */
    private function damagedTransfer(string $amount, string $type = 'next_week'): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);
        $categoryId = $this->categoryId($user, 'Transport');

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $weekOne = $plan->weeklyBudgets()->where('week_number', 1)->sole();
        $weekTwo = $plan->weeklyBudgets()->where('week_number', 2)->sole();

        if ($type === 'category') {
            // The pot was reduced, as the old code did, but the plan's stored
            // total never followed.
            $plan->budgetCategories()->updateOrCreate(
                ['category_id' => $categoryId],
                ['is_allowance' => true, 'budget_amount' => Money::sub('5000.00', $amount)],
            );
        } else {
            $weekTwo->forceFill(['adjusted_amount' => Money::sub($weekTwo->budget_amount, $amount)])->save();
        }

        $adjustment = BudgetAdjustment::create([
            'user_id' => $user->id,
            'monthly_plan_id' => $plan->id,
            'weekly_budget_id' => $type === 'category' ? $weekOne->id : $weekTwo->id,
            'source_weekly_budget_id' => $weekOne->id,
            'category_id' => $type === 'category' ? $categoryId : null,
            'type' => $type,
            'amount' => $amount,
            'original_amount' => $type === 'category' ? '5000.00' : $weekTwo->budget_amount,
            'adjusted_amount' => $type === 'category'
                ? Money::sub('5000.00', $amount)
                : Money::sub($weekTwo->budget_amount, $amount),
            'reason' => 'Overspend in week 1',
        ]);

        return [$plan->fresh(), $adjustment->fresh()];
    }
}
