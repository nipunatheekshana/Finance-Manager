<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Answers "can I afford this?" against the user's own plan.
 *
 * This is a budgeting check, not financial advice: it compares a proposed
 * purchase with the money the user has already set aside for spending and
 * reports what it would do to the rest of the cycle.
 */
class AffordabilityService
{
    public function __construct(
        private readonly FinancialPlanService $plans,
        private readonly BudgetCalculationService $budgets,
        private readonly CashFlowService $cashFlow,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(User $user, mixed $amount, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $amount = Money::of($amount);

        $plan = $this->plans->activePlanFor($user, $today);

        if ($plan === null) {
            return [
                'amount' => $amount,
                'verdict' => BudgetStatus::Warning->value,
                'headline' => 'No active plan',
                'message' => 'Set up this month\'s plan first so this check has a budget to work against.',
                'reasons' => [],
                'factors' => [],
                'disclaimer' => self::DISCLAIMER,
            ];
        }

        $monthly = $this->budgets->monthlySummary($plan, $today);
        $daily = $this->budgets->dailySummary($plan, $today);
        $week = $this->budgets->currentWeek($plan, $today);
        $weekly = $week ? $this->budgets->weeklySummary($week, $today) : null;

        $bills = $this->cashFlow->upcomingBills($plan, $today);
        $debtPayments = $this->cashFlow->upcomingDebtPayments($plan);
        $savings = $this->cashFlow->plannedSavings($plan);

        $monthRemaining = Money::of($monthly['remaining']);
        $weekRemaining = $weekly ? Money::of($weekly['remaining']) : $monthRemaining;
        $dayRemaining = Money::of($daily['remaining']);

        $afterMonth = Money::sub($monthRemaining, $amount);
        $afterWeek = Money::sub($weekRemaining, $amount);

        $reasons = [];
        $verdict = BudgetStatus::Safe;

        // Hard stop: it does not fit in what is left this month.
        if (Money::isNegative($afterMonth)) {
            $verdict = BudgetStatus::Over;
            $reasons[] = 'This is LKR '.$this->fmt(Money::abs($afterMonth)).' more than the spending money left for the rest of the cycle.';
        } elseif (Money::isNegative($afterWeek)) {
            $verdict = BudgetStatus::Over;
            $reasons[] = 'This is LKR '.$this->fmt(Money::abs($afterWeek)).' more than what is left in this week\'s budget.';
        } elseif ($weekly && Money::isPositive($weekRemaining) && Money::percentage($amount, $weekRemaining) >= 60) {
            $verdict = BudgetStatus::Warning;
            $reasons[] = 'This would use '.round(Money::percentage($amount, $weekRemaining)).'% of what is left in this week\'s budget.';
        }

        // Even when it fits the week, it may leave the month too thin.
        if ($verdict === BudgetStatus::Safe && Money::isPositive($monthRemaining)) {
            $shareOfMonth = Money::percentage($amount, $monthRemaining);

            if ($shareOfMonth >= 40) {
                $verdict = BudgetStatus::Warning;
                $reasons[] = 'This would use '.round($shareOfMonth).'% of your remaining spending money for the cycle.';
            }
        }

        // Commitments still to be met are funded outside the spending budget,
        // but flag it when the buffer is the only thing standing behind them.
        $committed = Money::add($bills['total'], $debtPayments['total'], $savings['total']);
        if (Money::isPositive($committed) && Money::isNegative($afterMonth)) {
            $reasons[] = 'You still have LKR '.$this->fmt($committed).' of bills, debt payments and savings to cover this cycle.';
        }

        if (Money::gt($amount, $dayRemaining) && $verdict === BudgetStatus::Safe) {
            $reasons[] = 'It is more than today\'s suggested limit, but it still fits the week.';
        }

        return [
            'amount' => $amount,
            'verdict' => $verdict->value,
            'headline' => match ($verdict) {
                BudgetStatus::Safe => 'Looks safe',
                BudgetStatus::Warning => 'Be careful',
                BudgetStatus::Over => 'Not recommended',
            },
            'message' => match ($verdict) {
                BudgetStatus::Safe => 'This purchase fits your current plan.',
                BudgetStatus::Warning => 'This purchase will significantly reduce your remaining budget.',
                BudgetStatus::Over => 'This purchase will push you past your planned budget.',
            },
            'reasons' => $reasons,
            'factors' => [
                'month_remaining' => $monthRemaining,
                'month_remaining_after' => $afterMonth,
                'week_remaining' => $weekRemaining,
                'week_remaining_after' => $afterWeek,
                'today_remaining' => $dayRemaining,
                'upcoming_bills' => $bills['total'],
                'upcoming_debt_payments' => $debtPayments['total'],
                'planned_savings' => $savings['total'],
                'buffer_remaining' => $plan->bufferRemaining(),
                'days_remaining' => $monthly['days_remaining'],
                'new_daily_limit' => $this->newDailyLimit($afterWeek, $weekly),
            ],
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    private const DISCLAIMER = 'This is a budgeting check against your own plan, not financial advice.';

    private function newDailyLimit(string $afterWeek, ?array $weekly): string
    {
        if ($weekly === null) {
            return '0.00';
        }

        $days = max(1, (int) $weekly['days_remaining']);

        return Money::div(Money::floorAtZero($afterWeek), (string) $days);
    }

    private function fmt(string $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
