<?php

namespace Tests\Feature;

use App\Enums\IncomeMode;
use App\Enums\IncomeSourceKind;
use App\Models\User;
use App\Services\FinancialPlanService;
use App\Services\IncomeForecastService;
use App\Services\IncomeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IncomeModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-25');
    }

    // ── Modes and their presets ──────────────────────────────────────────

    #[Test]
    public function each_mode_presets_a_sensible_anchor_and_funding_method(): void
    {
        $expected = [
            'salaried' => ['pay_day', 'fixed'],
            'self_employed' => ['calendar_month', 'draw'],
            'business' => ['calendar_month', 'forecast'],
            'hybrid' => ['pay_day', 'salary_plus_draw'],
        ];

        foreach ($expected as $mode => [$anchor, $funding]) {
            $case = IncomeMode::from($mode);
            $this->assertSame($anchor, $case->defaultCycleAnchor()->value, $mode);
            $this->assertSame($funding, $case->defaultFundingMethod()->value, $mode);
        }
    }

    #[Test]
    public function the_modes_endpoint_offers_only_workable_funding_methods(): void
    {
        $user = $this->makeUser();

        $data = $this->actingAs($user)->getJson('/api/income-modes')->assertOk()->json('data');

        $this->assertCount(4, $data['modes']);
        $this->assertSame(['fixed'], collect($data['funding_methods']['salaried'])->pluck('value')->all());
        $this->assertSame(
            ['draw', 'forecast', 'actual'],
            collect($data['funding_methods']['self_employed'])->pluck('value')->all(),
        );
    }

    // ── Cycle anchors ────────────────────────────────────────────────────

    #[Test]
    public function a_salaried_cycle_runs_from_pay_day_to_pay_day(): void
    {
        $user = $this->salaried();

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $this->assertSame('2026-09-25', $plan->cycle_start_date->toDateString());
        $this->assertSame('2026-10-24', $plan->cycle_end_date->toDateString());
    }

    #[Test]
    public function a_freelance_cycle_is_the_calendar_month(): void
    {
        $user = $this->freelance();

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $this->assertSame('2026-09-01', $plan->cycle_start_date->toDateString());
        $this->assertSame('2026-09-30', $plan->cycle_end_date->toDateString());
    }

    #[Test]
    public function a_calendar_month_account_maps_any_date_to_that_month(): void
    {
        $user = $this->freelance();
        $profile = $user->financialProfile;
        $cycles = app(\App\Services\BudgetCycleService::class);

        foreach (['2026-09-01', '2026-09-15', '2026-09-30'] as $date) {
            $period = $cycles->periodFor(\Carbon\CarbonImmutable::parse($date), $profile);
            $this->assertSame(['year' => 2026, 'month' => 9], $period, $date);
        }
    }

    // ── Funding methods ──────────────────────────────────────────────────

    #[Test]
    public function a_salaried_plan_is_funded_by_the_salary(): void
    {
        $user = $this->salaried();

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $this->assertSame('fixed', $plan->funding_method->value);
        $this->assertSame('280000.00', $plan->expected_income);
        $this->assertSame('0.00', $plan->drawn_amount);
    }

    #[Test]
    public function a_freelance_plan_is_funded_by_the_draw_however_lumpy_the_income(): void
    {
        $user = $this->freelance(draw: '200000.00');

        // A huge month followed by a thin one.
        $this->recordIncome($user, '400000.00', '2026-08-10');
        $this->recordIncome($user, '20000.00', '2026-09-05');

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        // The plan is steady regardless.
        $this->assertSame('draw', $plan->funding_method->value);
        $this->assertSame('200000.00', $plan->expected_income);
        $this->assertSame('200000.00', $plan->drawn_amount);
    }

    #[Test]
    public function a_business_plan_is_forecast_from_recent_months_and_discounted(): void
    {
        $user = $this->business(factor: 80);

        // Three finished months: 300k, 200k, 100k → average 200k.
        $this->recordIncome($user, '300000.00', '2026-06-15');
        $this->recordIncome($user, '200000.00', '2026-07-15');
        $this->recordIncome($user, '100000.00', '2026-08-15');

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        // 80% of 200,000, so a good month never sets an unaffordable plan.
        $this->assertSame('forecast', $plan->funding_method->value);
        $this->assertSame('160000.00', $plan->expected_income);
    }

    #[Test]
    public function an_actual_funded_plan_only_counts_money_that_has_arrived(): void
    {
        $user = $this->freelance(funding: 'actual');

        $this->recordIncome($user, '120000.00', '2026-09-08');

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $this->assertSame('actual', $plan->funding_method->value);
        $this->assertSame('120000.00', $plan->expected_income);
    }

    #[Test]
    public function invoiced_money_is_not_counted_until_it_is_received(): void
    {
        $user = $this->freelance(funding: 'actual');

        $this->recordIncome($user, '80000.00', '2026-09-08');
        $this->recordIncome($user, '150000.00', '2026-09-10', status: 'invoiced');

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        // Only the 80,000 actually in the bank.
        $this->assertSame('80000.00', $plan->expected_income);
    }

    #[Test]
    public function a_hybrid_plan_adds_the_salary_and_the_draw(): void
    {
        $user = $this->hybrid(salary: '150000.00', draw: '90000.00');

        $plan = app(FinancialPlanService::class)->draftFor($user, 2026, 9);

        $this->assertSame('salary_plus_draw', $plan->funding_method->value);
        $this->assertSame('240000.00', $plan->expected_income);
        // Only the draw comes out of the pot; the salary funds itself.
        $this->assertSame('90000.00', $plan->drawn_amount);
    }

    // ── Holding pot and runway ───────────────────────────────────────────

    #[Test]
    public function income_builds_the_pot_and_the_draw_empties_it(): void
    {
        $user = $this->freelance(draw: '100000.00');

        $this->recordIncome($user, '400000.00', '2026-09-05');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $runway = app(IncomeForecastService::class)->runway($user->fresh());

        $this->assertSame('400000.00', $runway['received']);
        $this->assertSame('100000.00', $runway['drawn']);
        $this->assertSame('300000.00', $runway['balance']);
        $this->assertSame(3.0, $runway['months']);
        $this->assertFalse($runway['is_low']);
    }

    #[Test]
    public function a_thin_pot_is_flagged_as_low_runway(): void
    {
        $user = $this->freelance(draw: '100000.00');
        $this->recordIncome($user, '150000.00', '2026-09-05');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $runway = app(IncomeForecastService::class)->runway($user->fresh());

        $this->assertSame('50000.00', $runway['balance']);
        $this->assertSame(0.5, $runway['months']);
        $this->assertTrue($runway['is_low']);
    }

    #[Test]
    public function a_hybrid_salary_does_not_inflate_the_pot(): void
    {
        $user = $this->hybrid(salary: '150000.00', draw: '90000.00');

        $salarySource = $user->incomeSources()->where('kind', IncomeSourceKind::Salary->value)->firstOrFail();

        // Salary in, plus freelance in.
        $this->recordIncome($user, '150000.00', '2026-09-25', sourceId: $salarySource->id);
        $this->recordIncome($user, '60000.00', '2026-09-12');

        $pot = app(IncomeForecastService::class)->holdingPot($user->fresh());

        // Only the freelance 60,000 is pot money.
        $this->assertSame('60000.00', $pot['received']);
    }

    #[Test]
    public function the_suggested_draw_discounts_the_average(): void
    {
        $user = $this->freelance(factor: 75);

        $this->recordIncome($user, '300000.00', '2026-06-15');
        $this->recordIncome($user, '200000.00', '2026-07-15');
        $this->recordIncome($user, '100000.00', '2026-08-15');

        $suggestion = app(IncomeForecastService::class)->suggestedDraw($user->fresh());

        $this->assertSame('200000.00', $suggestion['average']);
        $this->assertSame('150000.00', $suggestion['suggested']);
        $this->assertTrue($suggestion['has_history']);
    }

    #[Test]
    public function a_new_account_with_no_history_says_so_rather_than_guessing(): void
    {
        $user = $this->freelance();

        $suggestion = app(IncomeForecastService::class)->suggestedDraw($user);

        $this->assertFalse($suggestion['has_history']);
        $this->assertSame('0.00', $suggestion['average']);
    }

    // ── Switching modes ──────────────────────────────────────────────────

    #[Test]
    public function an_employee_can_become_self_employed(): void
    {
        $user = $this->salaried();

        $this->actingAs($user)->putJson('/api/income-modes', [
            'income_mode' => 'self_employed',
            'target_draw' => '150000.00',
        ])->assertOk();

        $profile = $user->fresh()->financialProfile;

        $this->assertSame('self_employed', $profile->income_mode->value);
        $this->assertSame('calendar_month', $profile->cycle_anchor->value);
        $this->assertSame('draw', $profile->funding_method->value);
        $this->assertSame('150000.00', $profile->target_draw);
        // No longer quoting a salary they do not receive.
        $this->assertSame('0.00', $profile->base_salary);
    }

    #[Test]
    public function leaving_self_employment_for_a_job_restores_the_salary_setup(): void
    {
        $user = $this->freelance();

        $this->actingAs($user)->putJson('/api/income-modes', [
            'income_mode' => 'salaried',
            'base_salary' => '280000.00',
            'cycle_start_day' => 25,
        ])->assertOk();

        $profile = $user->fresh()->financialProfile;

        $this->assertSame('salaried', $profile->income_mode->value);
        $this->assertSame('pay_day', $profile->cycle_anchor->value);
        $this->assertSame('280000.00', $profile->base_salary);
        $this->assertSame(25, $profile->cycle_start_day);
    }

    #[Test]
    public function switching_archives_the_salary_source_rather_than_deleting_it(): void
    {
        $user = $this->salaried();
        $this->recordIncome($user, '280000.00', '2026-08-25',
            sourceId: $user->incomeSources()->where('kind', 'salary')->value('id'));

        $this->actingAs($user)->putJson('/api/income-modes', [
            'income_mode' => 'self_employed',
            'target_draw' => '150000.00',
        ])->assertOk();

        $salarySource = $user->fresh()->incomeSources()->where('kind', 'salary')->first();

        $this->assertNotNull($salarySource, 'the salary source must survive the switch');
        $this->assertNotNull($salarySource->archived_at);
        $this->assertSame(1, $salarySource->transactions()->count());
    }

    #[Test]
    public function switching_never_rewrites_finished_plans(): void
    {
        $user = $this->salaried();

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user, 2026, 9);
        $planner->finalize($plan);

        $before = $plan->fresh()->only(['expected_income', 'cycle_start_date', 'cycle_end_date', 'funding_method']);

        $this->actingAs($user)->putJson('/api/income-modes', [
            'income_mode' => 'self_employed',
            'target_draw' => '100000.00',
        ])->assertOk();

        $after = $plan->fresh()->only(['expected_income', 'cycle_start_date', 'cycle_end_date', 'funding_method']);

        $this->assertEquals($before, $after);
    }

    #[Test]
    public function a_cycle_anchor_change_waits_until_the_live_plan_has_ended(): void
    {
        $user = $this->salaried();

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $preview = $this->actingAs($user)
            ->postJson('/api/income-modes/preview', [
                'income_mode' => 'self_employed',
                'target_draw' => '100000.00',
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($preview['cycle_anchor_changes']);
        $this->assertTrue($preview['deferred']);
        // The active cycle runs to 24 Oct, so the change lands the day after.
        $this->assertSame('2026-10-25', $preview['takes_effect_on']);
        $this->assertTrue($preview['history_preserved']);
    }

    #[Test]
    public function the_preview_changes_nothing(): void
    {
        $user = $this->salaried();

        $this->actingAs($user)->postJson('/api/income-modes/preview', [
            'income_mode' => 'business',
        ])->assertOk();

        $this->assertSame('salaried', $user->fresh()->financialProfile->income_mode->value);
    }

    #[Test]
    public function a_salaried_mode_requires_a_salary_figure(): void
    {
        $user = $this->freelance();

        $this->actingAs($user)
            ->putJson('/api/income-modes', ['income_mode' => 'salaried'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_salary');
    }

    #[Test]
    public function a_draw_funded_mode_requires_a_draw(): void
    {
        $user = $this->salaried();

        $this->actingAs($user)
            ->putJson('/api/income-modes', ['income_mode' => 'self_employed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_draw');
    }

    #[Test]
    public function changing_one_accounts_income_mode_leaves_others_alone(): void
    {
        $employee = $this->salaried();
        $freelancer = $this->freelance(draw: '120000.00');

        // The endpoint only ever acts on the signed-in account.
        $this->actingAs($freelancer)->putJson('/api/income-modes', [
            'income_mode' => 'business',
        ])->assertOk();

        $this->assertSame('business', $freelancer->fresh()->financialProfile->income_mode->value);
        $this->assertSame('salaried', $employee->fresh()->financialProfile->income_mode->value);
        $this->assertSame('280000.00', $employee->fresh()->financialProfile->base_salary);
    }

    #[Test]
    public function income_and_runway_are_scoped_to_the_signed_in_account(): void
    {
        $one = $this->freelance(draw: '100000.00');
        $two = $this->freelance(draw: '100000.00');

        $this->recordIncome($one, '300000.00', '2026-09-05');
        $this->recordIncome($two, '50000.00', '2026-09-05');

        $forecast = app(IncomeForecastService::class);

        $this->assertSame('300000.00', $forecast->holdingPot($one->fresh())['received']);
        $this->assertSame('50000.00', $forecast->holdingPot($two->fresh())['received']);
    }

    // ── Onboarding ───────────────────────────────────────────────────────

    #[Test]
    public function onboarding_can_set_up_a_freelancer(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/onboarding', [
            'income_mode' => 'self_employed',
            'target_draw' => '180000.00',
            'cycle_start_day' => 1,
            'default_buffer' => '30000.00',
        ])->assertCreated();

        $profile = $user->fresh()->financialProfile;

        $this->assertSame('self_employed', $profile->income_mode->value);
        $this->assertSame('calendar_month', $profile->cycle_anchor->value);
        $this->assertSame('180000.00', $profile->target_draw);
        $this->assertTrue($profile->hasCompletedOnboarding());

        // And a source to record client income against.
        $this->assertTrue($user->fresh()->incomeSources()->where('kind', 'client')->exists());
    }

    #[Test]
    public function onboarding_a_salaried_user_still_behaves_exactly_as_before(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/onboarding', [
            'income_mode' => 'salaried',
            'base_salary' => '280000.00',
            'cycle_start_day' => 25,
        ])->assertCreated();

        $profile = $user->fresh()->financialProfile;

        $this->assertSame('pay_day', $profile->cycle_anchor->value);
        $this->assertSame('fixed', $profile->funding_method->value);
        $this->assertSame('280000.00', $profile->base_salary);
        $this->assertTrue($user->fresh()->incomeSources()->where('kind', 'salary')->exists());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function salaried(): User
    {
        $user = $this->makeUser();
        app(IncomeModeService::class)->apply($user, IncomeMode::Salaried, [
            'base_salary' => '280000.00',
            'cycle_start_day' => 25,
        ]);

        return $user->fresh();
    }

    private function freelance(string $draw = '150000.00', string $funding = 'draw', int $factor = 80): User
    {
        $user = $this->makeUser();
        app(IncomeModeService::class)->apply($user, IncomeMode::SelfEmployed, [
            'target_draw' => $draw,
            'funding_method' => $funding,
            'forecast_factor' => $factor,
        ]);

        return $user->fresh();
    }

    private function business(int $factor = 80): User
    {
        $user = $this->makeUser();
        app(IncomeModeService::class)->apply($user, IncomeMode::Business, [
            'target_draw' => '0.00',
            'forecast_factor' => $factor,
        ]);

        return $user->fresh();
    }

    private function hybrid(string $salary, string $draw): User
    {
        $user = $this->makeUser();
        app(IncomeModeService::class)->apply($user, IncomeMode::Hybrid, [
            'base_salary' => $salary,
            'target_draw' => $draw,
            'cycle_start_day' => 25,
        ]);

        return $user->fresh();
    }

    private function recordIncome(
        User $user,
        string $amount,
        string $date,
        string $status = 'received',
        ?int $sourceId = null,
    ): void {
        $user->incomeTransactions()->create([
            'amount' => $amount,
            'received_date' => $date,
            'status' => $status,
            'type' => 'base',
            'income_source_id' => $sourceId,
        ]);
    }
}
