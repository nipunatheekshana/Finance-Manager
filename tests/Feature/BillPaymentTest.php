<?php

namespace Tests\Feature;

use App\Models\PlanFixedExpense;
use App\Models\User;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paying a bill happens during the cycle, not while drafting the plan — and
 * often days before the bill is actually due.
 */
class BillPaymentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_bill_can_be_settled_while_the_plan_is_active(): void
    {
        [$user, $bill] = $this->activePlanWithBill();

        $this->actingAs($user)
            ->putJson("/api/monthly-plans/{$bill->monthly_plan_id}/fixed-expenses/{$bill->id}", [
                'status' => 'paid',
            ])
            ->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->status);
        $this->assertNotNull($bill->paid_at, 'Paying a bill should stamp when it happened.');
    }

    #[Test]
    public function a_settled_bill_leaves_the_still_to_pay_list(): void
    {
        [$user, $bill] = $this->activePlanWithBill();

        $before = $this->actingAs($user)->getJson('/api/dashboard')->json('data.upcoming_bills.items');
        $this->assertContains('Electricity', array_column($before, 'name'));

        $this->actingAs($user)->putJson(
            "/api/monthly-plans/{$bill->monthly_plan_id}/fixed-expenses/{$bill->id}",
            ['status' => 'paid'],
        )->assertOk();

        $after = $this->actingAs($user)->getJson('/api/dashboard')->json('data.upcoming_bills.items');
        $this->assertNotContains('Electricity', array_column($after, 'name'));
    }

    #[Test]
    public function paying_early_does_not_change_the_spending_budget(): void
    {
        [$user, $bill] = $this->activePlanWithBill();
        $plan = $bill->monthlyPlan;

        $before = $plan->fresh()->spending_budget;

        $this->actingAs($user)->putJson(
            "/api/monthly-plans/{$plan->id}/fixed-expenses/{$bill->id}",
            ['status' => 'paid'],
        )->assertOk();

        // The money left income when the plan was built; settling the bill
        // must not take it out a second time.
        $this->assertSame($before, $plan->fresh()->spending_budget);
    }

    #[Test]
    public function a_bill_that_came_to_more_records_what_was_actually_paid(): void
    {
        [$user, $bill] = $this->activePlanWithBill();

        $this->actingAs($user)->putJson(
            "/api/monthly-plans/{$bill->monthly_plan_id}/fixed-expenses/{$bill->id}",
            ['status' => 'paid', 'actual_amount' => '13450.00'],
        )->assertOk();

        $bill->refresh();
        $this->assertSame('13450.00', $bill->effectiveAmount());
        $this->assertSame('12000.00', Money::of($bill->amount), 'The plan keeps what was budgeted.');
    }

    #[Test]
    public function another_account_cannot_settle_your_bill(): void
    {
        [, $bill] = $this->activePlanWithBill();

        $this->actingAs($this->makeUser())
            ->putJson("/api/monthly-plans/{$bill->monthly_plan_id}/fixed-expenses/{$bill->id}", [
                'status' => 'paid',
            ])
            ->assertForbidden();

        $this->assertSame('planned', $bill->fresh()->status);
    }

    /** @return array{0: User, 1: PlanFixedExpense} */
    private function activePlanWithBill(): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '100000.00', 'cycle_start_day' => 25]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);

        $bill = PlanFixedExpense::create([
            'monthly_plan_id' => $plan->id,
            'name' => 'Electricity',
            'amount' => '12000.00',
            'status' => 'planned',
            // Three days before the cycle ends: paying it today is early.
            'due_date' => $plan->cycle_end_date->copy()->subDays(3)->toDateString(),
        ]);

        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        return [$user->fresh(), $bill->fresh()];
    }
}
