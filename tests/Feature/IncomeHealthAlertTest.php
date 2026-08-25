<?php

namespace Tests\Feature;

use App\Enums\IncomeMode;
use App\Models\User;
use App\Services\FinancialPlanService;
use App\Services\IncomeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The warnings that only matter when income is irregular.
 */
class IncomeHealthAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-20');
    }

    #[Test]
    public function a_thin_pot_raises_a_low_runway_warning(): void
    {
        $user = $this->freelance(draw: '100000.00');
        $this->recordIncome($user, '120000.00', '2026-09-02');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $alert = $this->alertOfType($user, 'low_runway');

        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert['severity']);
        $this->assertStringContainsString('Less than a month', $alert['title']);
    }

    #[Test]
    public function drawing_more_than_earned_is_treated_as_critical(): void
    {
        $user = $this->freelance(draw: '100000.00');
        $this->recordIncome($user, '20000.00', '2026-09-02');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $alert = $this->alertOfType($user, 'low_runway');

        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert['severity']);
    }

    #[Test]
    public function a_healthy_pot_raises_nothing(): void
    {
        $user = $this->freelance(draw: '100000.00');
        $this->recordIncome($user, '600000.00', '2026-09-02');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $this->assertNull($this->alertOfType($user, 'low_runway'));
    }

    #[Test]
    public function an_overdue_invoice_is_flagged(): void
    {
        $user = $this->freelance();

        $user->incomeTransactions()->create([
            'amount' => '85000.00',
            'status' => 'invoiced',
            'received_date' => null,
            'due_date' => '2026-09-10',
            'type' => 'base',
            'reference' => 'INV-014',
        ]);

        $alert = $this->alertOfType($user, 'invoice_overdue');

        $this->assertNotNull($alert);
        $this->assertStringContainsString('1 payment is overdue', $alert['title']);
        $this->assertStringContainsString('85,000.00', $alert['message']);
    }

    #[Test]
    public function an_invoice_not_yet_due_is_left_alone(): void
    {
        $user = $this->freelance();

        $user->incomeTransactions()->create([
            'amount' => '85000.00',
            'status' => 'invoiced',
            'received_date' => null,
            'due_date' => '2026-10-10',
            'type' => 'base',
        ]);

        $this->assertNull($this->alertOfType($user, 'invoice_overdue'));
    }

    #[Test]
    public function a_cycle_running_short_of_plan_is_flagged_once_it_is_half_gone(): void
    {
        $user = $this->freelance(draw: '200000.00');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        // Two thirds through September with only a quarter of the money in.
        $this->recordIncome($user, '50000.00', '2026-09-05');

        $alert = $this->alertOfType($user, 'income_behind_plan');

        $this->assertNotNull($alert);
        $this->assertStringContainsString('behind plan', $alert['title']);
        $this->assertStringContainsString('25%', $alert['message']);
    }

    #[Test]
    public function a_cycle_on_track_is_not_flagged(): void
    {
        $user = $this->freelance(draw: '200000.00');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $this->recordIncome($user, '180000.00', '2026-09-05');

        $this->assertNull($this->alertOfType($user, 'income_behind_plan'));
    }

    #[Test]
    public function a_salaried_account_never_sees_these_warnings(): void
    {
        $user = $this->makeUser();
        app(IncomeModeService::class)->apply($user, IncomeMode::Salaried, [
            'base_salary' => '280000.00',
            'cycle_start_day' => 25,
        ]);

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user->fresh(), 2026, 8));

        foreach (['low_runway', 'invoice_overdue', 'income_behind_plan'] as $type) {
            $this->assertNull($this->alertOfType($user->fresh(), $type), $type);
        }
    }

    #[Test]
    public function these_warnings_can_be_switched_off(): void
    {
        $user = $this->freelance(draw: '100000.00');
        $this->recordIncome($user, '20000.00', '2026-09-02');

        $planner = app(FinancialPlanService::class);
        $planner->finalize($planner->draftFor($user, 2026, 9));

        $user->financialProfile->forceFill([
            'notification_settings' => ['income_health' => false],
        ])->save();

        $this->assertNull($this->alertOfType($user->fresh(), 'low_runway'));
    }

    /** @return array<string, mixed>|null */
    private function alertOfType(User $user, string $type): ?array
    {
        $alerts = $this->actingAs($user)->getJson('/api/alerts')->assertOk()->json('data');

        return collect($alerts)->firstWhere('type', $type);
    }

    private function freelance(string $draw = '150000.00'): User
    {
        $user = $this->makeUser();
        app(IncomeModeService::class)->apply($user, IncomeMode::SelfEmployed, [
            'target_draw' => $draw,
        ]);

        return $user->fresh();
    }

    private function recordIncome(User $user, string $amount, string $date): void
    {
        $user->incomeTransactions()->create([
            'amount' => $amount,
            'received_date' => $date,
            'status' => 'received',
            'type' => 'base',
        ]);
    }
}
