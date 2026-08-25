<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * An app-generated progress indicator between 0 and 100.
 *
 * This is not a credit score or any kind of professional assessment. It is a
 * weighted read of how closely the user is following their own plan, so the
 * weights live here and can be tuned in one place.
 */
class FinancialHealthService
{
    /**
     * Factor weights, totalling 100.
     *
     * @var array<string, int>
     */
    public const WEIGHTS = [
        'budget_adherence' => 25,
        'debt_reduction' => 25,
        'savings_rate' => 20,
        'emergency_fund' => 15,
        'overspending_frequency' => 10,
        'credit_utilisation' => 5,
    ];

    /** A fully funded emergency fund is three months of salary. */
    private const EMERGENCY_FUND_MONTHS = 3;

    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly FinancialPlanService $plans,
        private readonly SavingsService $savings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scoreFor(User $user, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $plan = $this->plans->activePlanFor($user, $today);

        if ($plan === null) {
            return [
                'score' => null,
                'has_data' => false,
                'message' => 'Create and finalise a monthly plan to start tracking your progress.',
                'factors' => [],
                'good' => [],
                'needs_attention' => [],
                'change_from_last_month' => null,
                'disclaimer' => self::DISCLAIMER,
            ];
        }

        $factors = $this->factorsFor($user, $plan, $today);
        $score = $this->combine($factors);

        $previous = $this->previousPlan($user, $plan);
        $previousScore = null;

        if ($previous !== null) {
            $previousScore = $this->combine($this->factorsFor($user, $previous, CarbonImmutable::instance($previous->cycle_end_date)));
        }

        [$good, $needsAttention] = $this->explain($factors);

        return [
            'score' => $score,
            'has_data' => true,
            'plan_label' => $plan->label(),
            'change_from_last_month' => $previousScore === null ? null : $score - $previousScore,
            'previous_score' => $previousScore,
            'factors' => array_values($factors),
            'good' => $good,
            'needs_attention' => $needsAttention,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    private const DISCLAIMER = 'This is an app-generated progress indicator, not a professional financial score.';

    /**
     * @return array<string, array<string, mixed>>
     */
    private function factorsFor(User $user, MonthlyPlan $plan, CarbonImmutable $today): array
    {
        return [
            'budget_adherence' => $this->budgetAdherence($plan, $today),
            'debt_reduction' => $this->debtReduction($user, $plan),
            'savings_rate' => $this->savingsRate($user, $plan),
            'emergency_fund' => $this->emergencyFund($user),
            'overspending_frequency' => $this->overspendingFrequency($plan, $today),
            'credit_utilisation' => $this->creditUtilisation($user),
        ];
    }

    /** Weighted sum of every factor's 0-1 rating, rounded to a whole number. */
    private function combine(array $factors): int
    {
        $total = 0.0;

        foreach ($factors as $key => $factor) {
            $total += $factor['rating'] * self::WEIGHTS[$key];
        }

        return (int) round(min(100, max(0, $total)));
    }

    /** How close spending is to the plan: on or under budget scores full marks. */
    private function budgetAdherence(MonthlyPlan $plan, CarbonImmutable $today): array
    {
        $monthly = $this->budgets->monthlySummary($plan, $today);
        $budget = Money::of($monthly['budget']);
        $spent = Money::of($monthly['spent']);

        if (! Money::isPositive($budget)) {
            return $this->factor('budget_adherence', 'Budget adherence', 0.5, 'No spending budget is set for this cycle.');
        }

        $used = Money::percentage($spent, $budget);

        // Over budget degrades from 1.0 down to 0 at 150% of budget.
        $rating = $used <= 100
            ? 1.0
            : max(0.0, 1 - (($used - 100) / 50));

        return $this->factor(
            'budget_adherence',
            'Budget adherence',
            $rating,
            $used <= 100
                ? 'Spending is within the plan at '.round($used).'% of budget.'
                : 'Spending is at '.round($used).'% of the planned budget.',
            ['percentage_used' => $used],
        );
    }

    /** Did total debt actually go down during this cycle? */
    private function debtReduction(User $user, MonthlyPlan $plan): array
    {
        $paid = Money::of($plan->debtPayments()->sum('amount'));
        $newSpending = Money::of(
            $plan->expenses()->whereNotNull('debt_id')->sum('amount')
        );

        $totalDebt = Money::of($user->debts()->active()->sum('current_balance'));

        if (Money::isZero($totalDebt) && Money::isZero($paid)) {
            return $this->factor('debt_reduction', 'Debt reduction', 1.0, 'You have no active debt.');
        }

        $net = Money::sub($paid, $newSpending);

        if (! Money::isPositive($paid)) {
            return $this->factor('debt_reduction', 'Debt reduction', 0.0, 'No debt payments recorded this cycle.', [
                'paid' => $paid,
                'new_spending' => $newSpending,
            ]);
        }

        // Full marks when payments comfortably outweigh new borrowing.
        $rating = Money::isPositive($net)
            ? min(1.0, Money::percentage($net, $paid) / 100)
            : 0.0;

        return $this->factor(
            'debt_reduction',
            'Debt reduction',
            $rating,
            Money::isPositive($net)
                ? 'Debt fell by LKR '.$this->fmt($net).' this cycle.'
                : 'New borrowing of LKR '.$this->fmt($newSpending).' cancelled out your payments.',
            ['paid' => $paid, 'new_spending' => $newSpending, 'net_reduction' => $net],
        );
    }

    /** Saved as a share of income, full marks at 20% or more. */
    private function savingsRate(User $user, MonthlyPlan $plan): array
    {
        $income = $plan->totalIncome();

        if (! Money::isPositive($income)) {
            return $this->factor('savings_rate', 'Savings rate', 0.0, 'No income recorded for this cycle.');
        }

        $saved = $this->savings->netSavedBetween(
            $user->id,
            CarbonImmutable::instance($plan->cycle_start_date),
            CarbonImmutable::instance($plan->cycle_end_date),
        );

        $rate = Money::percentage(Money::floorAtZero($saved), $income);
        $rating = min(1.0, $rate / 20);

        return $this->factor(
            'savings_rate',
            'Savings rate',
            $rating,
            'You saved '.round($rate, 1).'% of your income this cycle.',
            ['saved' => $saved, 'rate' => $rate],
        );
    }

    /** Emergency cover measured in months of salary. */
    private function emergencyFund(User $user): array
    {
        $profile = $this->plans->profileFor($user);
        $salary = Money::of($profile->base_salary);

        if (! Money::isPositive($salary)) {
            return $this->factor('emergency_fund', 'Emergency fund', 0.0, 'Set your salary to track emergency cover.');
        }

        $total = $this->savings->totalSaved($user->id);
        $target = Money::mul($salary, (string) self::EMERGENCY_FUND_MONTHS);
        $rating = min(1.0, (float) Money::div($total, $target));
        $months = round((float) Money::div($total, $salary), 1);

        return $this->factor(
            'emergency_fund',
            'Emergency fund',
            $rating,
            'Your savings cover about '.$months.' months of salary.',
            ['total_saved' => $total, 'target' => $target, 'months_covered' => $months],
        );
    }

    /** How many finished weeks of this cycle ran over budget. */
    private function overspendingFrequency(MonthlyPlan $plan, CarbonImmutable $today): array
    {
        $weeks = $plan->weeklyBudgets;
        $finished = $weeks->filter(
            fn ($week) => CarbonImmutable::instance($week->end_date)->lte($today)
        );

        if ($finished->isEmpty()) {
            return $this->factor('overspending_frequency', 'Overspending', 1.0, 'No completed weeks to judge yet.');
        }

        $over = $this->budgets->overspentWeeks($plan, $today)
            ->filter(fn ($week) => CarbonImmutable::instance($week->end_date)->lte($today))
            ->count();

        $rating = 1 - ($over / $finished->count());

        return $this->factor(
            'overspending_frequency',
            'Overspending',
            max(0.0, $rating),
            $over === 0
                ? 'No completed week went over budget.'
                : $over.' of '.$finished->count().' completed weeks went over budget.',
            ['weeks_over' => $over, 'weeks_completed' => $finished->count()],
        );
    }

    /** Lower credit-card utilisation scores higher; full marks under 30%. */
    private function creditUtilisation(User $user): array
    {
        $cards = $user->debts()
            ->active()
            ->where('type', 'credit_card')
            ->whereNotNull('credit_limit')
            ->get();

        if ($cards->isEmpty()) {
            return $this->factor('credit_utilisation', 'Credit utilisation', 1.0, 'No credit-card limit is being tracked.');
        }

        $balance = Money::sum($cards->pluck('current_balance'));
        $limit = Money::sum($cards->pluck('credit_limit'));
        $utilisation = Money::percentage($balance, $limit);

        $rating = match (true) {
            $utilisation <= 30 => 1.0,
            $utilisation >= 90 => 0.0,
            default => 1 - (($utilisation - 30) / 60),
        };

        return $this->factor(
            'credit_utilisation',
            'Credit utilisation',
            $rating,
            'Your cards are at '.round($utilisation).'% of their limit.',
            ['utilisation' => $utilisation],
        );
    }

    /**
     * Split factors into what is going well and what needs work.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function explain(array $factors): array
    {
        $good = [];
        $needsAttention = [];

        foreach ($factors as $factor) {
            if ($factor['rating'] >= 0.75) {
                $good[] = $factor['detail'];
            } elseif ($factor['rating'] < 0.5) {
                $needsAttention[] = $factor['detail'];
            }
        }

        return [$good, $needsAttention];
    }

    private function factor(string $key, string $label, float $rating, string $detail, array $data = []): array
    {
        $rating = min(1.0, max(0.0, $rating));

        return [
            'key' => $key,
            'label' => $label,
            'weight' => self::WEIGHTS[$key],
            'rating' => $rating,
            'points' => round($rating * self::WEIGHTS[$key], 1),
            'detail' => $detail,
            'data' => $data,
        ];
    }

    private function previousPlan(User $user, MonthlyPlan $plan): ?MonthlyPlan
    {
        return MonthlyPlan::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($plan) {
                $query->where('year', '<', $plan->year)
                    ->orWhere(fn ($q) => $q->where('year', $plan->year)->where('month', '<', $plan->month));
            })
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->with('weeklyBudgets')
            ->first();
    }

    private function fmt(string $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
