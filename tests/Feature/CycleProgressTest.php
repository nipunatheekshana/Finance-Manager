<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\PlanFixedExpense;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\CycleProgressService;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The board that answers "how is this cycle actually going" for every part of
 * the plan at once.
 */
class CycleProgressTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_every_part_of_the_cycle(): void
    {
        [$user] = $this->activeCycle();

        $data = $this->actingAs($user)->getJson('/api/cycle-progress')
            ->assertOk()
            ->json('data');

        foreach (['plan', 'overall', 'income', 'bills', 'allowances', 'debts', 'savings',
            'spending', 'buffer'] as $section) {
            $this->assertArrayHasKey($section, $data);
        }
    }

    #[Test]
    public function a_bill_that_is_paid_counts_towards_progress(): void
    {
        [$user, $plan, $bill] = $this->activeCycle();

        $before = $this->progress($user)['bills'];
        $this->assertSame('pending', $before['status']);
        $this->assertSame(0, $before['settled_count']);

        $this->actingAs($user)->putJson(
            "/api/monthly-plans/{$plan->id}/fixed-expenses/{$bill->id}",
            ['status' => 'paid'],
        )->assertOk();

        $after = $this->progress($user)['bills'];
        $this->assertSame('done', $after['status']);
        $this->assertSame(1, $after['settled_count']);
        $this->assertSame('0.00', $after['outstanding']);
    }

    #[Test]
    public function a_part_paid_debt_reads_as_part_done(): void
    {
        [$user, , , $debt] = $this->activeCycle();

        $this->actingAs($user)->postJson("/api/debts/{$debt->id}/payments", [
            'amount' => '5000.00',
            'payment_date' => '2026-09-26',
        ])->assertCreated();

        $debts = $this->progress($user)['debts'];

        $this->assertSame('partial', $debts['status']);
        $this->assertSame('5000.00', $debts['settled']);
        $this->assertSame('10000.00', $debts['outstanding']);
        $this->assertSame('partial', $debts['items'][0]['status']);
    }

    #[Test]
    public function a_skipped_bill_is_not_counted_as_owed(): void
    {
        [$user, $plan, $bill] = $this->activeCycle();

        $this->actingAs($user)->putJson(
            "/api/monthly-plans/{$plan->id}/fixed-expenses/{$bill->id}",
            ['status' => 'skipped'],
        )->assertOk();

        $bills = $this->progress($user)['bills'];

        $this->assertSame(0, $bills['count'], 'A skipped bill is not owed.');
        $this->assertSame('0.00', $bills['planned']);
        // It is still listed, so the user can see what was skipped.
        $this->assertCount(1, $bills['items']);
        $this->assertFalse($bills['items'][0]['counts']);
    }

    #[Test]
    public function progress_is_measured_against_how_far_the_cycle_has_run(): void
    {
        [$user, $plan] = $this->activeCycle();

        // Day 1 of a 30-day cycle with nothing settled: nothing is late yet.
        $this->freezeOn('2026-09-25');
        $day1 = app(CycleProgressService::class)->forPlan($plan->fresh());
        $this->assertTrue($day1['overall']['on_track']);
        $this->assertSame(1, $day1['plan']['days_elapsed']);

        // Three weeks in with the same nothing settled: behind.
        $this->freezeOn('2026-10-16');
        $late = app(CycleProgressService::class)->forPlan($plan->fresh());
        $this->assertFalse($late['overall']['on_track']);
    }

    #[Test]
    public function day_to_day_spending_is_kept_out_of_the_commitment_total(): void
    {
        [$user, $plan] = $this->activeCycle();

        $progress = $this->progress($user);

        // Committed is bills + debt + savings only. Spending money is not a
        // commitment to discharge.
        $expected = \App\Support\Money::add(
            $progress['bills']['planned'],
            $progress['debts']['planned'],
            $progress['savings']['planned'],
        );

        $this->assertSame($expected, $progress['overall']['committed']);
    }

    #[Test]
    public function a_past_cycle_can_be_looked_up_by_id(): void
    {
        [$user, $plan] = $this->activeCycle();

        $data = $this->actingAs($user)->getJson("/api/cycle-progress?plan={$plan->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($plan->id, $data['plan']['id']);
    }

    #[Test]
    public function another_accounts_cycle_is_not_readable_or_even_visible(): void
    {
        [, $plan] = $this->activeCycle();

        // Not found, not forbidden: whether the plan exists is not disclosed.
        $this->actingAs($this->makeUser())
            ->getJson("/api/cycle-progress?plan={$plan->id}")
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function progress(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/cycle-progress')->json('data');
    }

    /** @return array{0: User, 1: MonthlyPlan, 2: PlanFixedExpense, 3: Debt} */
    private function activeCycle(): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $debt = Debt::create([
            'user_id' => $user->id,
            'name' => 'Visa',
            'type' => 'credit_card',
            'original_amount' => '100000.00',
            'current_balance' => '100000.00',
            'minimum_payment' => '5000.00',
            'planned_payment' => '15000.00',
            'interest_rate' => '24.00',
            'due_day' => 15,
        ]);

        SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency fund',
            'target_amount' => '300000.00',
            'current_amount' => '0.00',
            'monthly_target' => '10000.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '10000.00',
            'priority' => 1,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);

        $bill = PlanFixedExpense::create([
            'monthly_plan_id' => $plan->id,
            'name' => 'Electricity',
            'amount' => '12000.00',
            'status' => 'planned',
            'due_date' => '2026-10-05',
        ]);

        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        return [$user->fresh(), $plan->fresh(), $bill->fresh(), $debt->fresh()];
    }
}
