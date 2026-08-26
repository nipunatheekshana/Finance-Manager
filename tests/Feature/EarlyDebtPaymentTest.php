<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\User;
use App\Models\PlanDebtAllocation;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A planned card payment can be made any day of the cycle, including before
 * the statement due day — the plan has to notice either way.
 */
class EarlyDebtPaymentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_dashboard_lists_what_is_still_owed_on_the_plan(): void
    {
        [$user] = $this->activePlanWithCard();

        $items = $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->json('data.upcoming_debt_payments.items');

        $this->assertSame('Visa', $items[0]['name']);
        $this->assertSame('15000.00', $items[0]['amount']);
        $this->assertSame('0.00', $items[0]['paid']);
    }

    #[Test]
    public function paying_early_credits_the_plan_and_clears_the_list(): void
    {
        [$user, $debt, $plan] = $this->activePlanWithCard();

        // Due day is the 15th; today is the 25th of the previous month.
        $this->actingAs($user)
            ->postJson("/api/debts/{$debt->id}/payments", [
                'amount' => '15000.00',
                'payment_date' => '2026-09-26',
            ])
            ->assertCreated();

        $allocation = PlanDebtAllocation::query()
            ->where('monthly_plan_id', $plan->id)
            ->where('debt_id', $debt->id)
            ->first();

        $this->assertSame('15000.00', $allocation->paid_amount);

        $items = $this->actingAs($user)->getJson('/api/dashboard')
            ->json('data.upcoming_debt_payments.items');

        $this->assertSame([], $items, 'A fully paid instalment is no longer outstanding.');
    }

    #[Test]
    public function a_part_payment_leaves_the_rest_outstanding(): void
    {
        [$user, $debt] = $this->activePlanWithCard();

        $this->actingAs($user)
            ->postJson("/api/debts/{$debt->id}/payments", [
                'amount' => '6000.00',
                'payment_date' => '2026-09-26',
            ])
            ->assertCreated();

        $items = $this->actingAs($user)->getJson('/api/dashboard')
            ->json('data.upcoming_debt_payments.items');

        $this->assertSame('9000.00', $items[0]['amount']);
        $this->assertSame('6000.00', $items[0]['paid']);
    }

    /** @return array{0: User, 1: Debt, 2: \App\Models\MonthlyPlan} */
    private function activePlanWithCard(): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '150000.00', 'cycle_start_day' => 25]);

        $debt = Debt::create([
            'user_id' => $user->id,
            'name' => 'Visa',
            'type' => 'credit_card',
            'original_amount' => '80000.00',
            'current_balance' => '80000.00',
            'minimum_payment' => '8000.00',
            'planned_payment' => '15000.00',
            'interest_rate' => '0.00',
            'due_day' => 15,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        return [$user->fresh(), $debt->fresh(), $plan->fresh()];
    }
}
