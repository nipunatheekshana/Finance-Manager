<?php

namespace App\Services;

use App\Models\Debt;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Estimates how long a debt takes to clear at a given monthly payment.
 *
 * Every figure this returns is an estimate. It assumes the planned payment is
 * made on time each month and that no new spending is charged to the debt; when
 * either changes the estimate is simply recalculated from the live balance.
 */
class DebtPayoffService
{
    /** Stop projecting beyond 50 years. */
    private const MAX_MONTHS = 600;

    /**
     * @return array<string, mixed>
     */
    public function project(Debt $debt, ?string $monthlyPayment = null, ?CarbonImmutable $from = null): array
    {
        $balance = Money::of($debt->current_balance);
        $payment = Money::of($monthlyPayment ?? $this->defaultPayment($debt));
        $from = ($from ?? CarbonImmutable::today())->startOfDay();

        $hasInterest = $debt->interest_rate !== null && Money::isPositive((string) $debt->interest_rate);
        $monthlyRate = $hasInterest
            ? Money::div((string) $debt->interest_rate, '1200')  // annual % -> monthly fraction
            : '0.00';

        if (! Money::isPositive($balance)) {
            return $this->clearedResult($hasInterest);
        }

        if (! Money::isPositive($payment)) {
            return $this->stalledResult($balance, $hasInterest, 'No payment amount is set for this debt.');
        }

        // With interest, a payment at or below the first month's interest never
        // reduces the principal.
        if ($hasInterest) {
            $firstInterest = Money::mul($balance, $monthlyRate);

            if (Money::lte($payment, $firstInterest)) {
                return $this->stalledResult(
                    $balance,
                    true,
                    'The planned payment does not cover the monthly interest, so the balance would not go down.'
                );
            }
        }

        $schedule = [];
        $totalInterest = '0.00';
        $totalPaid = '0.00';
        $cursor = $from;
        $month = 0;

        while (Money::isPositive($balance) && $month < self::MAX_MONTHS) {
            $month++;
            $cursor = $cursor->addMonthNoOverflow();

            $interest = $hasInterest ? Money::mul($balance, $monthlyRate) : '0.00';
            $balanceWithInterest = Money::add($balance, $interest);

            // The final payment is only ever what is still owed.
            $thisPayment = Money::min($payment, $balanceWithInterest);
            $principal = Money::sub($thisPayment, $interest);
            $balance = Money::floorAtZero(Money::sub($balanceWithInterest, $thisPayment));

            $totalInterest = Money::add($totalInterest, $interest);
            $totalPaid = Money::add($totalPaid, $thisPayment);

            $schedule[] = [
                'month_number' => $month,
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('M Y'),
                'payment' => $thisPayment,
                'interest' => $interest,
                'principal' => Money::floorAtZero($principal),
                'remaining_balance' => $balance,
            ];
        }

        $cleared = Money::isZero($balance);

        return [
            'is_estimate' => true,
            'has_interest' => $hasInterest,
            'interest_rate' => $hasInterest ? (string) $debt->interest_rate : null,
            'monthly_payment' => $payment,
            'will_be_paid_off' => $cleared,
            'estimated_months' => $cleared ? $month : null,
            'estimated_payoff_date' => $cleared ? $cursor->toDateString() : null,
            'estimated_payoff_label' => $cleared ? $cursor->format('F Y') : null,
            'estimated_total_interest' => $totalInterest,
            'estimated_total_paid' => $totalPaid,
            'remaining_after_projection' => $balance,
            'schedule' => $schedule,
            'note' => $hasInterest
                ? 'Estimated using the configured annual interest rate, applied monthly.'
                : 'No interest rate is set, so this is a simple no-interest estimate.',
            'warning' => null,
        ];
    }

    /**
     * Project every active debt and total them up.
     *
     * @param  iterable<Debt>  $debts
     * @return array<string, mixed>
     */
    public function projectAll(iterable $debts, ?CarbonImmutable $from = null): array
    {
        $projections = [];
        $totalBalance = '0.00';
        $totalMonthly = '0.00';
        $longestMonths = 0;

        foreach ($debts as $debt) {
            $projection = $this->project($debt, null, $from);

            $totalBalance = Money::add($totalBalance, $debt->current_balance);
            $totalMonthly = Money::add($totalMonthly, $projection['monthly_payment']);

            if ($projection['estimated_months'] !== null) {
                $longestMonths = max($longestMonths, (int) $projection['estimated_months']);
            }

            $projections[] = ['debt_id' => $debt->id, 'name' => $debt->name] + $projection;
        }

        return [
            'total_balance' => $totalBalance,
            'total_monthly_payment' => $totalMonthly,
            'debt_free_in_months' => $longestMonths > 0 ? $longestMonths : null,
            'projections' => $projections,
        ];
    }

    private function defaultPayment(Debt $debt): string
    {
        $planned = Money::of($debt->planned_payment);

        return Money::isPositive($planned) ? $planned : Money::of($debt->minimum_payment);
    }

    /** @return array<string, mixed> */
    private function clearedResult(bool $hasInterest): array
    {
        return [
            'is_estimate' => true,
            'has_interest' => $hasInterest,
            'interest_rate' => null,
            'monthly_payment' => '0.00',
            'will_be_paid_off' => true,
            'estimated_months' => 0,
            'estimated_payoff_date' => null,
            'estimated_payoff_label' => null,
            'estimated_total_interest' => '0.00',
            'estimated_total_paid' => '0.00',
            'remaining_after_projection' => '0.00',
            'schedule' => [],
            'note' => 'This debt is already cleared.',
            'warning' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function stalledResult(string $balance, bool $hasInterest, string $warning): array
    {
        return [
            'is_estimate' => true,
            'has_interest' => $hasInterest,
            'interest_rate' => null,
            'monthly_payment' => '0.00',
            'will_be_paid_off' => false,
            'estimated_months' => null,
            'estimated_payoff_date' => null,
            'estimated_payoff_label' => null,
            'estimated_total_interest' => '0.00',
            'estimated_total_paid' => '0.00',
            'remaining_after_projection' => $balance,
            'schedule' => [],
            'note' => 'Payoff cannot be estimated yet.',
            'warning' => $warning,
        ];
    }
}
