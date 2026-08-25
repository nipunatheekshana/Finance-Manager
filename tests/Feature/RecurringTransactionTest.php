<?php

namespace Tests\Feature;

use App\Models\RecurringTransaction;
use App\Services\RecurringTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecurringTransactionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_weekly_expense_is_counted_from_real_dates_not_multiplied_by_four(): void
    {
        $user = $this->makeUser();

        // 2 packs a week at 3,200 = 6,400 every Monday.
        $cigarettes = $this->recurring($user, [
            'name' => 'Cigarettes',
            'amount' => '6400.00',
            'frequency' => 'weekly',
            'day_of_week' => CarbonImmutable::MONDAY,
        ]);

        $service = app(RecurringTransactionService::class);

        // 25 Jul to 24 Aug 2026 contains five Mondays, not four.
        $count = $service->occurrenceCount(
            $cigarettes,
            CarbonImmutable::parse('2026-07-25'),
            CarbonImmutable::parse('2026-08-24'),
        );

        $this->assertSame(5, $count);
        $this->assertSame(
            '32000.00',
            $service->plannedAmountBetween(
                $cigarettes,
                CarbonImmutable::parse('2026-07-25'),
                CarbonImmutable::parse('2026-08-24'),
            ),
        );
    }

    #[Test]
    public function a_shorter_window_of_the_same_weekly_expense_costs_less(): void
    {
        $user = $this->makeUser();
        $cigarettes = $this->recurring($user, [
            'name' => 'Cigarettes',
            'amount' => '6400.00',
            'frequency' => 'weekly',
            'day_of_week' => CarbonImmutable::MONDAY,
        ]);

        // 1 to 28 Feb 2026 contains four Mondays.
        $this->assertSame(
            '25600.00',
            app(RecurringTransactionService::class)->plannedAmountBetween(
                $cigarettes,
                CarbonImmutable::parse('2026-02-01'),
                CarbonImmutable::parse('2026-02-28'),
            ),
        );
    }

    #[Test]
    public function a_monthly_expense_falls_once_per_calendar_month_in_the_window(): void
    {
        $user = $this->makeUser();
        $gym = $this->recurring($user, ['name' => 'Gym', 'amount' => '3000.00', 'due_day' => 26]);

        $dates = app(RecurringTransactionService::class)->occurrencesBetween(
            $gym,
            CarbonImmutable::parse('2026-07-25'),
            CarbonImmutable::parse('2026-08-24'),
        );

        $this->assertCount(1, $dates);
        $this->assertSame('2026-07-26', $dates[0]->toDateString());
    }

    #[Test]
    public function a_monthly_due_day_past_the_month_end_is_clamped(): void
    {
        $user = $this->makeUser();
        $bill = $this->recurring($user, ['name' => 'Rent', 'amount' => '50000.00', 'due_day' => 31]);

        $dates = app(RecurringTransactionService::class)->occurrencesBetween(
            $bill,
            CarbonImmutable::parse('2026-02-01'),
            CarbonImmutable::parse('2026-02-28'),
        );

        $this->assertCount(1, $dates);
        $this->assertSame('2026-02-28', $dates[0]->toDateString());
    }

    #[Test]
    public function a_daily_expense_counts_every_day_in_the_window(): void
    {
        $user = $this->makeUser();
        $coffee = $this->recurring($user, [
            'name' => 'Coffee',
            'amount' => '350.00',
            'frequency' => 'daily',
        ]);

        $this->assertSame(
            10,
            app(RecurringTransactionService::class)->occurrenceCount(
                $coffee,
                CarbonImmutable::parse('2026-09-01'),
                CarbonImmutable::parse('2026-09-10'),
            ),
        );
    }

    #[Test]
    public function a_custom_interval_repeats_every_n_days_from_the_start(): void
    {
        $user = $this->makeUser();
        $haircut = $this->recurring($user, [
            'name' => 'Haircut',
            'amount' => '1500.00',
            'frequency' => 'custom',
            'interval_days' => 21,
            'start_date' => '2026-09-01',
        ]);

        $dates = app(RecurringTransactionService::class)->occurrencesBetween(
            $haircut,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-31'),
        );

        $this->assertSame(
            ['2026-09-01', '2026-09-22', '2026-10-13'],
            array_map(fn ($date) => $date->toDateString(), $dates),
        );
    }

    #[Test]
    public function an_expense_outside_its_own_start_and_end_dates_does_not_occur(): void
    {
        $user = $this->makeUser();
        $finished = $this->recurring($user, [
            'name' => 'Old subscription',
            'amount' => '1000.00',
            'due_day' => 5,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->assertSame(
            0,
            app(RecurringTransactionService::class)->occurrenceCount(
                $finished,
                CarbonImmutable::parse('2026-09-01'),
                CarbonImmutable::parse('2026-09-30'),
            ),
        );
    }

    #[Test]
    public function a_variable_bill_records_a_range_and_plans_with_the_expected_amount(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/recurring-transactions', [
            'name' => 'SLT',
            'amount' => '9000.00',
            'minimum_amount' => '8000.00',
            'maximum_amount' => '10000.00',
            'amount_type' => 'range',
            'frequency' => 'monthly',
            'due_day' => 28,
            'start_date' => '2026-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.is_variable', true)
            ->assertJsonPath('data.amount', '9000.00');
    }

    #[Test]
    public function the_expected_amount_must_sit_inside_the_stated_range(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/recurring-transactions', [
            'name' => 'SLT',
            'amount' => '12000.00',
            'minimum_amount' => '8000.00',
            'maximum_amount' => '10000.00',
            'frequency' => 'monthly',
            'due_day' => 28,
            'start_date' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function a_minimum_above_the_maximum_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/recurring-transactions', [
            'name' => 'SLT',
            'amount' => '9000.00',
            'minimum_amount' => '11000.00',
            'maximum_amount' => '10000.00',
            'frequency' => 'monthly',
            'due_day' => 28,
            'start_date' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('minimum_amount');
    }

    #[Test]
    public function a_custom_frequency_needs_an_interval(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/recurring-transactions', [
            'name' => 'Something',
            'amount' => '1000.00',
            'frequency' => 'custom',
            'start_date' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('interval_days');
    }

    private function recurring(\App\Models\User $user, array $attributes): RecurringTransaction
    {
        return $user->recurringTransactions()->create($attributes + [
            'frequency' => 'monthly',
            'amount_type' => 'fixed',
            'start_date' => '2026-01-01',
            'active' => true,
        ]);
    }
}
