<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Salary day is not a one-shot: people correct a typo, or record the figure
 * again once the transfer actually lands. Saving it twice must leave the same
 * state as saving it once.
 */
class RecordSalaryTwiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recording_the_salary_twice_keeps_one_income_record(): void
    {
        [$user, $plan] = $this->draftPlan();

        $this->recordIncome($user, $plan, '280000.00');
        $this->recordIncome($user, $plan, '280000.00');

        $this->assertSame(1, $plan->incomeTransactions()->count());
        $this->assertSame('280000.00', $plan->incomeTransactions()->sum('amount'));
    }

    #[Test]
    public function correcting_the_salary_replaces_the_figure_rather_than_adding_to_it(): void
    {
        [$user, $plan] = $this->draftPlan();

        // A typo, then the correction.
        $this->recordIncome($user, $plan, '2800000.00');
        $this->recordIncome($user, $plan, '280000.00');

        $this->assertSame('280000.00', $plan->incomeTransactions()->sum('amount'));
        $this->assertSame('280000.00', $plan->fresh()->actual_income);
    }

    #[Test]
    public function the_income_screen_does_not_show_the_salary_twice(): void
    {
        [$user, $plan] = $this->draftPlan();

        $this->recordIncome($user, $plan, '280000.00');
        $this->recordIncome($user, $plan, '295000.00');

        $rows = $this->actingAs($user)->getJson('/api/income')->assertOk()->json('data');
        $salaryRows = array_filter($rows, fn (array $row) => $row['type'] === 'base');

        $this->assertCount(1, $salaryRows, 'One salary in, one salary listed.');
        $this->assertSame('295000.00', reset($salaryRows)['amount']);
    }

    #[Test]
    public function extra_income_is_only_split_once(): void
    {
        [$user, $plan, $debt] = $this->draftPlanWithDebt();

        // 20,000 more than expected, twice over.
        $this->recordIncome($user, $plan, '300000.00');
        $afterFirst = $this->plannedFor($plan, $debt);

        $this->recordIncome($user, $plan, '300000.00');
        $afterSecond = $this->plannedFor($plan, $debt);

        $this->assertSame($afterFirst, $afterSecond, 'The same extra must not be allocated twice.');
    }

    #[Test]
    public function a_corrected_salary_takes_the_extra_split_back_out(): void
    {
        [$user, $plan, $debt] = $this->draftPlanWithDebt();

        $baseline = $this->plannedFor($plan, $debt);

        $this->recordIncome($user, $plan, '300000.00');
        $this->assertNotSame($baseline, $this->plannedFor($plan, $debt), 'The extra should have been split.');

        // The transfer was actually the expected amount after all.
        $this->recordIncome($user, $plan, '280000.00');

        $this->assertSame($baseline, $this->plannedFor($plan, $debt));
        $this->assertSame('0.00', $plan->fresh()->extra_income);
    }

    #[Test]
    public function the_cleanup_command_removes_duplicates_left_by_the_old_behaviour(): void
    {
        [$user, $plan] = $this->draftPlan();

        // Exactly what the old code wrote: the same salary, twice.
        foreach (['280000.00', '295000.00'] as $amount) {
            $plan->incomeTransactions()->create([
                'user_id' => $user->id,
                'amount' => $amount,
                'received_date' => '2026-09-25',
                'type' => 'base',
                'description' => 'Salary for '.$plan->label(),
            ]);
        }

        $this->artisan('finance:dedupe-salaries')->assertSuccessful();
        $this->assertSame(2, $plan->incomeTransactions()->count(), 'A dry run must delete nothing.');

        $this->artisan('finance:dedupe-salaries --force')->assertSuccessful();

        $this->assertSame(1, $plan->incomeTransactions()->count());
        $this->assertSame('295000.00', $plan->incomeTransactions()->value('amount'), 'The latest correction survives.');
    }

    private function recordIncome(User $user, MonthlyPlan $plan, string $amount): void
    {
        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$plan->id}/income", ['actual_income' => $amount])
            ->assertOk();
    }

    private function plannedFor(MonthlyPlan $plan, Debt $debt): string
    {
        return (string) $plan->debtAllocations()->where('debt_id', $debt->id)->value('planned_amount');
    }

    /** @return array{0: User, 1: MonthlyPlan} */
    private function draftPlan(): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '280000.00', 'cycle_start_day' => 25]);
        $plan = app(FinancialPlanService::class)->draftFor($user->fresh(), 2026, 9);

        return [$user->fresh(), $plan];
    }

    /** @return array{0: User, 1: MonthlyPlan, 2: Debt} */
    private function draftPlanWithDebt(): array
    {
        $this->freezeOn('2026-09-25');

        $user = $this->makeUser(['base_salary' => '280000.00', 'cycle_start_day' => 25]);

        $debt = Debt::create([
            'user_id' => $user->id,
            'name' => 'Visa',
            'type' => 'credit_card',
            'original_amount' => '200000.00',
            'current_balance' => '200000.00',
            'minimum_payment' => '8000.00',
            'planned_payment' => '15000.00',
            'interest_rate' => '28.00',
            'due_day' => 15,
        ]);

        $plan = app(FinancialPlanService::class)->draftFor($user->fresh(), 2026, 9);

        return [$user->fresh(), $plan, $debt->fresh()];
    }
}
