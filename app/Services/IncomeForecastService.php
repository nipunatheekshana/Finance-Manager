<?php

namespace App\Services;

use App\Enums\FundingMethod;
use App\Enums\IncomeSourceKind;
use App\Models\FinancialProfile;
use App\Models\IncomeTransaction;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides what a cycle's plan is funded by.
 *
 * This is the single point where a salaried account and an irregular one differ.
 * Everything downstream — weekly budgets, daily limits, overspend, surplus —
 * works from the figure this produces and never asks how it was arrived at.
 *
 * The holding pot is the idea that makes lumpy income workable: money received
 * goes into the pot, the plan draws a steady amount out of it, and the balance
 * left is runway.
 */
class IncomeForecastService
{
    public function __construct(private readonly BudgetCycleService $cycles) {}

    /**
     * What should fund the plan for a period, and where that figure came from.
     *
     * @return array<string, mixed>
     */
    public function fundingFor(User $user, int $year, int $month, ?FinancialProfile $profile = null): array
    {
        $profile = $profile ?? $user->financialProfile;
        $method = $profile->funding_method;

        [$start, $end] = $this->cycles->cycleFor($year, $month, $profile);

        $salary = Money::of($profile->base_salary);
        $draw = Money::of($profile->target_draw);

        return match ($method) {
            FundingMethod::Fixed => $this->result($method, $salary, '0.00', [
                'salary' => $salary,
                'explanation' => 'Your salary.',
            ]),

            FundingMethod::Draw => $this->result($method, $draw, $draw, [
                'draw' => $draw,
                'explanation' => 'The steady amount you pay yourself.',
            ]),

            FundingMethod::SalaryPlusDraw => $this->result(
                $method,
                Money::add($salary, $draw),
                $draw,
                [
                    'salary' => $salary,
                    'draw' => $draw,
                    'explanation' => 'Your salary plus the draw from your own work.',
                ],
            ),

            FundingMethod::Forecast => $this->forecastResult($user, $profile),

            FundingMethod::Actual => $this->actualResult($user, $start, $end),
        };
    }

    /** Just the figure, for callers that do not need the reasoning. */
    public function expectedIncomeFor(User $user, int $year, int $month, ?FinancialProfile $profile = null): string
    {
        return $this->fundingFor($user, $year, $month, $profile)['amount'];
    }

    /**
     * Average received income across recent completed cycles.
     */
    public function rollingAverage(User $user, ?int $months = null): array
    {
        $profile = $user->financialProfile;
        $months = $months ?? max(1, (int) $profile->forecast_months);

        $today = CarbonImmutable::today();
        $period = $this->cycles->currentPeriodFor($profile, $today);

        $totals = [];

        // Look back over whole cycles only; the current one is still running.
        for ($i = 1; $i <= $months; $i++) {
            $anchor = CarbonImmutable::create($period['year'], $period['month'], 1)
                ->subMonthsNoOverflow($i);

            [$start, $end] = $this->cycles->cycleFor($anchor->year, $anchor->month, $profile);

            $totals[] = [
                'label' => $anchor->format('M Y'),
                'amount' => $this->potIncomeBetween($user, $start, $end),
            ];
        }

        $withIncome = array_values(array_filter(
            $totals,
            fn (array $row) => Money::isPositive($row['amount']),
        ));

        $average = $withIncome === []
            ? '0.00'
            : Money::div(Money::sum(array_column($withIncome, 'amount')), (string) count($withIncome));

        return [
            'months' => $months,
            'cycles' => array_reverse($totals),
            'cycles_with_income' => count($withIncome),
            'average' => $average,
            'lowest' => $withIncome === [] ? '0.00' : min(array_column($withIncome, 'amount')),
            'highest' => $withIncome === [] ? '0.00' : max(array_column($withIncome, 'amount')),
        ];
    }

    /**
     * A draw the user could sustain: the rolling average discounted by their
     * caution factor, so a good month does not set an unaffordable expectation.
     */
    public function suggestedDraw(User $user): array
    {
        $profile = $user->financialProfile;
        $history = $this->rollingAverage($user);
        $factor = max(1, (int) $profile->forecast_factor);

        $suggested = Money::percentOf($history['average'], (string) $factor);

        return [
            'suggested' => $suggested,
            'average' => $history['average'],
            'lowest' => $history['lowest'],
            'factor' => $factor,
            'has_history' => $history['cycles_with_income'] > 0,
            'explanation' => $history['cycles_with_income'] > 0
                ? "{$factor}% of your average over the last {$history['months']} cycles."
                : 'Not enough history yet — set a figure you can live on.',
        ];
    }

    /**
     * The pot: income received but not yet drawn.
     *
     * Salary is excluded — it funds its plan directly rather than being banked
     * and drawn down.
     *
     * @return array<string, mixed>
     */
    public function holdingPot(User $user): array
    {
        $received = $this->potIncomeBetween($user, null, null);

        $drawn = Money::of(
            MonthlyPlan::query()
                ->where('user_id', $user->id)
                ->whereNotNull('finalized_at')
                ->sum('drawn_amount')
        );

        return [
            'received' => $received,
            'drawn' => $drawn,
            'balance' => Money::sub($received, $drawn),
        ];
    }

    /**
     * How long the pot would last at the current draw.
     *
     * @return array<string, mixed>
     */
    public function runway(User $user): array
    {
        $profile = $user->financialProfile;
        $pot = $this->holdingPot($user);
        $draw = Money::of($profile->target_draw);

        $months = Money::isPositive($draw)
            ? round((float) Money::div($pot['balance'], $draw), 1)
            : null;

        return $pot + [
            'draw' => $draw,
            'months' => $months,
            'covered_until' => $months !== null && $months > 0
                ? CarbonImmutable::today()->addDays((int) round($months * 30))->toDateString()
                : null,
            'is_low' => $months !== null && $months < 1,
            'is_negative' => Money::isNegative($pot['balance']),
        ];
    }

    /**
     * Income actually received in a window, by status and by source.
     *
     * @return array<string, mixed>
     */
    public function summaryBetween(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        $received = Money::of(
            $this->receivedQuery($user)
                ->whereBetween('received_date', [$start->toDateString(), $end->toDateString()])
                ->sum('amount')
        );

        $outstanding = Money::of(
            IncomeTransaction::query()
                ->where('user_id', $user->id)
                ->outstanding()
                ->sum('amount')
        );

        $overdue = Money::of(
            IncomeTransaction::query()
                ->where('user_id', $user->id)
                ->outstanding()
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', CarbonImmutable::today()->toDateString())
                ->sum('amount')
        );

        return [
            'received' => $received,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
        ];
    }

    /**
     * Income that flows into the pot. Salary is deliberately excluded.
     */
    public function potIncomeBetween(User $user, ?CarbonInterface $start, ?CarbonInterface $end): string
    {
        $query = $this->receivedQuery($user)
            ->whereDoesntHave('incomeSource', fn ($q) => $q->where('kind', IncomeSourceKind::Salary->value));

        if ($start !== null && $end !== null) {
            $query->whereBetween('received_date', [$start->toDateString(), $end->toDateString()]);
        }

        return Money::of($query->sum('amount'));
    }

    private function receivedQuery(User $user)
    {
        return IncomeTransaction::query()->where('user_id', $user->id)->received();
    }

    /** @return array<string, mixed> */
    private function forecastResult(User $user, FinancialProfile $profile): array
    {
        $history = $this->rollingAverage($user);
        $factor = max(1, (int) $profile->forecast_factor);
        $amount = Money::percentOf($history['average'], (string) $factor);

        return $this->result(FundingMethod::Forecast, $amount, '0.00', [
            'average' => $history['average'],
            'factor' => $factor,
            'has_history' => $history['cycles_with_income'] > 0,
            'explanation' => $history['cycles_with_income'] > 0
                ? "{$factor}% of your average income over the last {$history['months']} cycles."
                : 'No earning history yet, so there is nothing to forecast from.',
        ]);
    }

    /** @return array<string, mixed> */
    private function actualResult(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $received = Money::of(
            $this->receivedQuery($user)
                ->whereBetween('received_date', [$start->toDateString(), $end->toDateString()])
                ->sum('amount')
        );

        return $this->result(FundingMethod::Actual, $received, '0.00', [
            'explanation' => 'Only the income that has actually arrived this cycle.',
            'grows_during_cycle' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function result(FundingMethod $method, string $amount, string $drawn, array $detail): array
    {
        return [
            'method' => $method->value,
            'method_label' => $method->label(),
            'amount' => $amount,
            // The portion taken out of the holding pot.
            'drawn_amount' => $drawn,
            'uses_holding_pot' => $method->usesHoldingPot(),
            'is_progressive' => $method->isProgressive(),
        ] + $detail;
    }
}
