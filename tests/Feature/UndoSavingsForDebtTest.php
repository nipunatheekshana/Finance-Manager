<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\FinancialPlanService;
use App\Services\SavingsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money already moved into a goal is not out of reach: taking this month's
 * saving back out is a legitimate way to pay for something that came up.
 */
class UndoSavingsForDebtTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_withdrawal_reduces_what_the_cycle_counts_as_saved(): void
    {
        [$user, $plan, $goal] = $this->cycleWithSavings();

        $this->deposit($goal, '20000.00', $plan);
        $this->assertSame('20000.00', $this->savedAmount($plan, $goal));

        app(SavingsService::class)->withdraw($goal->fresh(), [
            'amount' => '8000.00',
            'transaction_date' => '2026-08-28',
        ]);

        // 20,000 in and 8,000 back out is 12,000 put aside this cycle, not
        // 20,000 — counting deposits alone overstated it.
        $this->assertSame('12000.00', $this->savedAmount($plan, $goal));
    }

    #[Test]
    public function this_months_saving_is_offered_as_a_way_to_pay_for_a_new_debt(): void
    {
        [$user, $plan, $goal] = $this->cycleWithSavings();
        $this->deposit($goal, '20000.00', $plan);

        $debt = $this->newDebt($user, '10000.00');

        $options = $this->actingAs($user)
            ->getJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}/options?amount=10000.00")
            ->assertOk()
            ->json('data.options');

        $withdrawal = collect($options)->firstWhere('source', 'savings_withdrawal');

        $this->assertNotNull($withdrawal);
        $this->assertTrue($withdrawal['available']);
        $this->assertSame('20000.00', $withdrawal['current']);

        // The plain "save less" option cannot help: it is all already saved.
        $this->assertFalse(collect($options)->firstWhere('source', 'savings')['available']);
    }

    #[Test]
    public function taking_it_back_moves_the_real_money_out_of_the_goal(): void
    {
        [$user, $plan, $goal] = $this->cycleWithSavings();
        $this->deposit($goal, '20000.00', $plan);

        $debt = $this->newDebt($user, '10000.00');
        $spendingBefore = $plan->fresh()->spending_budget;

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}", [
                'amount' => '10000.00',
                'source' => 'savings_withdrawal',
            ])
            ->assertOk();

        $plan->refresh();

        $this->assertSame('10000.00', Money::of($goal->fresh()->current_amount));
        $this->assertSame('10000.00', $this->savedAmount($plan, $goal));
        $this->assertSame('10000.00', $plan->savings, 'The plan now intends to save less.');
        $this->assertSame($spendingBefore, $plan->spending_budget, 'Day-to-day money is untouched.');
    }

    #[Test]
    public function the_withdrawal_is_recorded_against_the_goal(): void
    {
        [$user, $plan, $goal] = $this->cycleWithSavings();
        $this->deposit($goal, '20000.00', $plan);

        $debt = $this->newDebt($user, '10000.00');

        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}", [
            'amount' => '10000.00',
            'source' => 'savings_withdrawal',
        ])->assertOk();

        $withdrawal = $goal->transactions()->where('type', 'withdrawal')->sole();

        $this->assertSame('10000.00', Money::of($withdrawal->amount));
        $this->assertStringContainsString('New loan', (string) $withdrawal->description);
    }

    #[Test]
    public function it_cannot_take_out_more_than_was_put_in_this_cycle(): void
    {
        [$user, $plan, $goal] = $this->cycleWithSavings();
        $this->deposit($goal, '3000.00', $plan);

        $debt = $this->newDebt($user, '10000.00');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/pending-debts/{$debt->id}", [
                'amount' => '10000.00',
                'source' => 'savings_withdrawal',
            ])
            ->assertStatus(422);

        $this->assertSame('3000.00', Money::of($goal->fresh()->current_amount));
    }

    private function deposit(SavingsGoal $goal, string $amount, MonthlyPlan $plan): void
    {
        app(SavingsService::class)->deposit($goal, [
            'amount' => $amount,
            'transaction_date' => '2026-08-26',
            'monthly_plan_id' => $plan->id,
        ]);
    }

    private function savedAmount(MonthlyPlan $plan, SavingsGoal $goal): string
    {
        return Money::of(
            $plan->savingsAllocations()->where('savings_goal_id', $goal->id)->value('saved_amount')
        );
    }

    private function newDebt(User $user, string $payment): Debt
    {
        return Debt::create([
            'user_id' => $user->id,
            'name' => 'New loan',
            'type' => 'loan',
            'original_amount' => '400000.00',
            'current_balance' => '400000.00',
            'minimum_payment' => $payment,
            'planned_payment' => $payment,
            'interest_rate' => '18.00',
            'due_day' => 10,
        ]);
    }

    /** @return array{0: User, 1: MonthlyPlan, 2: SavingsGoal} */
    private function cycleWithSavings(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $goal = SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency fund',
            'target_amount' => '500000.00',
            'current_amount' => '0.00',
            'monthly_target' => '20000.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '20000.00',
            'priority' => 1,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        return [$user->fresh(), $plan->fresh(), $goal->fresh()];
    }
}
