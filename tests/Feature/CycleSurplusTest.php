<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\CycleSurplusService;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CycleSurplusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_unspent_budget_and_unused_buffer(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        $summary = app(CycleSurplusService::class)->summarise($plan);

        // Budget 41,500 − spent 24,100 = 17,400 unspent, plus a 20,000 buffer.
        $this->assertSame('17400.00', $summary['unspent_budget']);
        $this->assertSame('20000.00', $summary['unused_buffer']);
        $this->assertSame('37400.00', $summary['total']);
        $this->assertTrue($summary['needs_decision']);
    }

    #[Test]
    public function buffer_already_spent_is_not_offered_again(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $plan->forceFill(['buffer_used' => '5000.00'])->save();

        $summary = app(CycleSurplusService::class)->summarise($plan->fresh());

        $this->assertSame('15000.00', $summary['unused_buffer']);
        $this->assertSame('32400.00', $summary['total']);
    }

    #[Test]
    public function an_overspent_cycle_has_no_surplus(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '50000.00', buffer: '0.00');

        $summary = app(CycleSurplusService::class)->summarise($plan);

        $this->assertSame('0.00', $summary['unspent_budget']);
        $this->assertSame('0.00', $summary['total']);
        $this->assertFalse($summary['has_surplus']);
        $this->assertFalse($summary['needs_decision']);
    }

    #[Test]
    public function a_cycle_still_running_is_not_asked_about(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00', today: '2026-10-01');

        $summary = app(CycleSurplusService::class)->summarise($plan);

        $this->assertFalse($summary['cycle_ended']);
        $this->assertFalse($summary['needs_decision']);
    }

    #[Test]
    public function the_options_show_the_effect_of_each_choice(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        $options = $this->actingAs($user)
            ->getJson("/api/monthly-plans/{$plan->id}/surplus")
            ->assertOk()
            ->json('data');

        $this->assertSame('37400.00', $options['total']);

        // Paying the card: 282,000 → 244,600.
        $card = collect($options['debts'])->firstWhere('name', 'Credit Card');
        $this->assertSame('282000.00', $card['balance']);
        $this->assertSame('244600.00', $card['resulting_balance']);

        // Or the fund: 65,000 → 102,400.
        $goal = collect($options['savings_goals'])->firstWhere('name', 'Emergency Fund');
        $this->assertSame('65000.00', $goal['current_amount']);
        $this->assertSame('102400.00', $goal['resulting_amount']);

        $this->assertSame('October 2026', $options['carry_forward']['next_label']);
        $this->assertSame('37400.00', $options['carry_forward']['resulting_opening_balance']);
    }

    #[Test]
    public function looking_at_the_options_moves_nothing(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $card = $user->debts()->firstOrFail();
        $goal = $user->savingsGoals()->firstOrFail();

        $this->actingAs($user)->getJson("/api/monthly-plans/{$plan->id}/surplus")->assertOk();

        $this->assertSame('282000.00', $card->fresh()->current_balance);
        $this->assertSame('65000.00', $goal->fresh()->current_amount);
        $this->assertNull($plan->fresh()->surplus_resolved_at);
    }

    #[Test]
    public function it_can_pay_down_a_card(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $card = $user->debts()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [
                    ['type' => 'debt', 'debt_id' => $card->id, 'amount' => '37400.00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('allocated', '37400.00');

        $this->assertSame('244600.00', $card->fresh()->current_balance);

        // Recorded as a real payment, not a silent balance edit.
        $this->assertDatabaseHas('debt_payments', [
            'debt_id' => $card->id,
            'amount' => '37400.00',
            'notes' => 'Leftover from September 2026',
        ]);
    }

    #[Test]
    public function paying_a_card_from_surplus_does_not_burn_an_installment(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        $lees = Debt::create([
            'user_id' => $user->id,
            'name' => 'Lees',
            'type' => 'installment',
            'original_amount' => '504000.00',
            'current_balance' => '462000.00',
            'minimum_payment' => '42000.00',
            'planned_payment' => '42000.00',
            'remaining_installments' => 11,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [['type' => 'debt', 'debt_id' => $lees->id, 'amount' => '10000.00']],
            ])
            ->assertOk();

        // An extra payment reduces the balance without consuming a scheduled
        // installment.
        $this->assertSame('452000.00', $lees->fresh()->current_balance);
        $this->assertSame(11, $lees->fresh()->remaining_installments);
    }

    #[Test]
    public function it_can_move_the_money_into_a_savings_goal(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $goal = $user->savingsGoals()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [
                    ['type' => 'savings', 'savings_goal_id' => $goal->id, 'amount' => '37400.00'],
                ],
            ])
            ->assertOk();

        $this->assertSame('102400.00', $goal->fresh()->current_amount);

        $this->assertDatabaseHas('savings_transactions', [
            'savings_goal_id' => $goal->id,
            'type' => 'deposit',
            'amount' => '37400.00',
            'description' => 'Leftover from September 2026',
        ]);
    }

    #[Test]
    public function it_can_carry_the_money_into_the_next_cycle(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [['type' => 'carry_forward', 'amount' => '37400.00']],
            ])
            ->assertOk();

        $this->assertSame('37400.00', $plan->fresh()->carried_forward);

        // The next plan opens with it, on top of salary.
        $next = app(FinancialPlanService::class)->draftFor($user->fresh(), 2026, 10);

        $this->assertSame('37400.00', $next->opening_balance);
        $this->assertSame('317400.00', $next->totalIncome());
    }

    #[Test]
    public function carrying_forward_tops_up_a_plan_that_already_exists(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        // The next month is already drafted before the decision is made.
        $next = app(FinancialPlanService::class)->draftFor($user->fresh(), 2026, 10);
        $this->assertSame('0.00', $next->opening_balance);

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [['type' => 'carry_forward', 'amount' => '37400.00']],
            ])
            ->assertOk();

        $this->assertSame('37400.00', $next->fresh()->opening_balance);
    }

    #[Test]
    public function it_can_split_the_money_across_several_destinations(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $card = $user->debts()->firstOrFail();
        $goal = $user->savingsGoals()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [
                    ['type' => 'debt', 'debt_id' => $card->id, 'amount' => '20000.00'],
                    ['type' => 'savings', 'savings_goal_id' => $goal->id, 'amount' => '10000.00'],
                    ['type' => 'carry_forward', 'amount' => '7400.00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('allocated', '37400.00')
            ->assertJsonPath('left_in_bank', '0.00');

        $this->assertSame('262000.00', $card->fresh()->current_balance);
        $this->assertSame('75000.00', $goal->fresh()->current_amount);
        $this->assertSame('7400.00', $plan->fresh()->carried_forward);
    }

    #[Test]
    public function leaving_it_in_the_bank_changes_nothing_but_stops_the_prompt(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $card = $user->debts()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", ['allocations' => []])
            ->assertOk()
            ->assertJsonPath('allocated', '0.00')
            ->assertJsonPath('left_in_bank', '37400.00');

        $this->assertSame('282000.00', $card->fresh()->current_balance);
        $this->assertNotNull($plan->fresh()->surplus_resolved_at);
        $this->assertSame('37400.00', $plan->fresh()->surplus_amount);
    }

    #[Test]
    public function it_refuses_to_allocate_more_than_was_left_over(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $card = $user->debts()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [['type' => 'debt', 'debt_id' => $card->id, 'amount' => '50000.00']],
            ])
            ->assertStatus(422);

        $this->assertSame('282000.00', $card->fresh()->current_balance);
        $this->assertNull($plan->fresh()->surplus_resolved_at);
    }

    #[Test]
    public function a_cycle_cannot_be_settled_twice(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $card = $user->debts()->firstOrFail();

        $payload = ['allocations' => [['type' => 'debt', 'debt_id' => $card->id, 'amount' => '10000.00']]];

        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/surplus", $payload)->assertOk();
        $this->actingAs($user)->postJson("/api/monthly-plans/{$plan->id}/surplus", $payload)->assertStatus(422);

        // Only the first allocation landed.
        $this->assertSame('272000.00', $card->fresh()->current_balance);
    }

    #[Test]
    public function a_running_cycle_cannot_be_settled_early(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00', today: '2026-10-01');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", ['allocations' => []])
            ->assertStatus(422);
    }

    #[Test]
    public function sweeping_the_buffer_marks_it_as_used(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $goal = $user->savingsGoals()->firstOrFail();

        // 17,400 unspent plus 5,000 of the buffer.
        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [
                    ['type' => 'savings', 'savings_goal_id' => $goal->id, 'amount' => '22400.00'],
                ],
            ])
            ->assertOk();

        $this->assertSame('5000.00', $plan->fresh()->buffer_used);
        $this->assertSame('15000.00', $plan->fresh()->bufferRemaining());
    }

    #[Test]
    public function allocations_within_the_unspent_budget_leave_the_buffer_alone(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $goal = $user->savingsGoals()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [
                    ['type' => 'savings', 'savings_goal_id' => $goal->id, 'amount' => '10000.00'],
                ],
            ])
            ->assertOk();

        $this->assertSame('0.00', $plan->fresh()->buffer_used);
    }

    #[Test]
    public function the_pending_endpoint_surfaces_an_unsettled_cycle(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        $pending = $this->actingAs($user)->getJson('/api/cycle-surplus')->assertOk()->json('data');

        $this->assertSame($plan->id, $pending['plan_id']);
        $this->assertSame('37400.00', $pending['total']);

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", ['allocations' => []])
            ->assertOk();

        // Once settled it stops appearing.
        $this->assertNull($this->actingAs($user)->getJson('/api/cycle-surplus')->json('data'));
    }

    #[Test]
    public function a_finished_cycle_raises_an_alert(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        $alerts = $this->actingAs($user)->getJson('/api/alerts')->assertOk()->json('data');
        $surplus = collect($alerts)->firstWhere('type', 'cycle_surplus');

        $this->assertNotNull($surplus);
        $this->assertStringContainsString('37,400', $surplus['title']);
    }

    #[Test]
    public function the_carried_balance_increases_the_next_cycles_spending_budget(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');

        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", [
                'allocations' => [['type' => 'carry_forward', 'amount' => '37400.00']],
            ])
            ->assertOk();

        $planner = app(FinancialPlanService::class);
        $next = $planner->draftFor($user->fresh(), 2026, 10);
        $summary = $planner->allocationSummary($next);

        $this->assertSame('280000.00', $summary['salary_income']);
        $this->assertSame('37400.00', $summary['opening_balance']);
        $this->assertSame('317400.00', $summary['total_income']);

        // Nothing allocated yet, so it all lands in spending.
        $this->assertSame('317400.00', $summary['spending_budget']);
    }

    #[Test]
    public function one_account_cannot_settle_anothers_cycle(): void
    {
        [$user, $plan] = $this->finishedCycle(spent: '24100.00');
        $other = $this->makeUser();

        $this->actingAs($other)->getJson("/api/monthly-plans/{$plan->id}/surplus")->assertForbidden();
        $this->actingAs($other)
            ->postJson("/api/monthly-plans/{$plan->id}/surplus", ['allocations' => []])
            ->assertForbidden();

        $this->assertNull($plan->fresh()->surplus_resolved_at);
    }

    /**
     * A September cycle (25 Sep – 24 Oct) that has finished, with a 41,500
     * spending budget, a 20,000 buffer, a card and a savings goal.
     *
     * @return array{0: User, 1: MonthlyPlan}
     */
    private function finishedCycle(string $spent, string $buffer = '20000.00', string $today = '2026-10-28'): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '280000.00', 'cycle_start_day' => 25]);

        Debt::create([
            'user_id' => $user->id,
            'name' => 'Credit Card',
            'type' => 'credit_card',
            'original_amount' => '377000.00',
            'current_balance' => '282000.00',
            'credit_limit' => '500000.00',
            // No planned or minimum payment: this card exists purely as a
            // destination for the surplus, not as part of the plan.
            'minimum_payment' => '0.00',
            'planned_payment' => '0.00',
            'due_day' => 1,
            'status' => 'active',
        ]);

        SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency Fund',
            'target_amount' => '300000.00',
            'current_amount' => '65000.00',
            'monthly_target' => '0.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '0.00',
            'priority' => 1,
            'status' => 'active',
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);

        // Income chosen so the spending budget lands on 41,500 after the buffer.
        $plan->forceFill(['buffer' => $buffer])->save();
        $planner->recordActualIncome(
            $plan->fresh(),
            \App\Support\Money::add('41500.00', $buffer),
            applySplit: false,
        );
        $planner->finalize($plan->fresh());

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $spent,
            'expense_date' => '2026-09-26',
        ]);

        // Move the clock past the end of the cycle.
        $this->freezeOn($today);

        return [$user->fresh(), $plan->fresh(['weeklyBudgets'])];
    }
}
