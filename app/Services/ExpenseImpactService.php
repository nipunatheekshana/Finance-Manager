<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What a proposed expense would do to the budgets it touches.
 *
 * Recording an expense is never blocked — the money has already been spent, and
 * a tool that refuses to write it down just stops being trusted. But crossing a
 * weekly limit should not slip by unnoticed either, so this answers "what will
 * this do?" before the save, and "what just happened?" after it.
 */
class ExpenseImpactService
{
    public function __construct(
        private readonly FinancialPlanService $plans,
        private readonly BudgetCalculationService $budgets,
    ) {}

    /**
     * Project the effect of an expense without writing anything.
     *
     * @return array<string, mixed>
     */
    public function preview(
        User $user,
        mixed $amount,
        ?string $date = null,
        ?int $categoryId = null,
        ?int $excludeExpenseId = null,
    ): array {
        $amount = Money::of($amount);
        $on = CarbonImmutable::parse($date ?? CarbonImmutable::today())->startOfDay();

        $plan = $this->plans->activePlanFor($user, $on);

        if ($plan === null) {
            return $this->noPlanResult($amount);
        }

        // When editing, the expense's existing amount is already counted in the
        // totals, so take it out before adding the new one.
        $existing = $this->existingAmount($user, $excludeExpenseId);

        // An allowance pays for its own category up to the amount reserved, so
        // only what spills past it lands on the week. Warning about the weekly
        // budget for money that was set aside on purpose would be telling the
        // user off for following their own plan.
        $allowance = $this->allowanceImpact($plan, $categoryId, $amount, $existing);
        $chargedToWeek = $allowance === null ? $amount : $allowance['from_day_to_day'];

        $week = $this->budgets->currentWeek($plan, $on);
        $weekImpact = $week === null
            ? null
            : $this->project(
                $this->budgets->weeklySummary($week, $on),
                $chargedToWeek,
                $existing,
                ['id' => $week->id, 'week_number' => $week->week_number],
            );

        $monthImpact = $this->project(
            $this->budgets->monthlySummary($plan, $on),
            $chargedToWeek,
            $existing,
        );

        $categoryImpact = $this->categoryImpact($plan, $categoryId, $amount, $existing);

        $willExceedWeek = $weekImpact !== null && $weekImpact['status_after'] === BudgetStatus::Over->value;
        $alreadyOverWeek = $weekImpact !== null && $weekImpact['status_before'] === BudgetStatus::Over->value;

        return [
            'amount' => $amount,
            'date' => $on->toDateString(),
            'has_plan' => true,
            'week' => $weekImpact,
            'month' => $monthImpact,
            'category' => $categoryImpact,
            'allowance' => $allowance,
            'buffer_remaining' => $plan->bufferRemaining(),

            // The case that needs a decision: this expense is what tips the
            // week over, rather than landing on an already-overspent week.
            'will_exceed_week' => $willExceedWeek && ! $alreadyOverWeek,
            'already_over_week' => $alreadyOverWeek,
            'will_exceed_category' => $categoryImpact !== null
                && $categoryImpact['status_after'] === BudgetStatus::Over->value
                && $categoryImpact['status_before'] !== BudgetStatus::Over->value,
            'needs_decision' => $willExceedWeek,
            'headline' => $allowance !== null && Money::isZero($allowance['from_day_to_day'])
                ? $this->allowanceHeadline($allowance)
                : $this->headline($weekImpact, $willExceedWeek, $alreadyOverWeek),
        ];
    }

    /**
     * How much of a proposed expense its allowance covers.
     *
     * @return array<string, mixed>|null
     */
    private function allowanceImpact(
        \App\Models\MonthlyPlan $plan,
        ?int $categoryId,
        string $amount,
        string $existing,
    ): ?array {
        $state = $this->budgets->allowanceStateFor($plan, $categoryId, $existing);

        if ($state === null) {
            return null;
        }

        $covered = Money::min($amount, $state['remaining']);

        return [
            'category_id' => $state['category_id'],
            'name' => $this->budgets->allowanceNameFor($plan, $state['category_id']),
            'allocated' => $state['allocated'],
            'spent_before' => $state['spent'],
            'remaining_before' => $state['remaining'],
            'covered' => $covered,
            'from_day_to_day' => Money::sub($amount, $covered),
            'remaining_after' => Money::floorAtZero(Money::sub($state['remaining'], $amount)),
        ];
    }

    /** @param array<string, mixed> $allowance */
    private function allowanceHeadline(array $allowance): string
    {
        return 'Comes out of your '.$allowance['name'].' allowance — LKR '
            .number_format((float) $allowance['remaining_after'], 2).' left of LKR '
            .number_format((float) $allowance['allocated'], 2).'.';
    }

    /**
     * The state of a week after an expense has been written, used to decide
     * whether to put the overspend choices in front of the user.
     *
     * @return array<string, mixed>|null
     */
    public function weekStateAfter(User $user, string $date): ?array
    {
        $on = CarbonImmutable::parse($date)->startOfDay();
        $plan = $this->plans->activePlanFor($user, $on);

        if ($plan === null) {
            return null;
        }

        $week = $this->budgets->currentWeek($plan, $on);

        if ($week === null) {
            return null;
        }

        $summary = $this->budgets->weeklySummary($week, $on);

        return [
            'weekly_budget_id' => $week->id,
            'week_number' => $week->week_number,
            'status' => $summary['status'],
            'over_by' => $summary['over_by'],
            'remaining' => $summary['remaining'],
            'is_over' => $summary['status'] === BudgetStatus::Over->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function project(array $summary, string $amount, string $existing, array $extra = []): array
    {
        $budget = Money::of($summary['budget']);
        $spentBefore = Money::floorAtZero(Money::sub($summary['spent'], $existing));
        $spentAfter = Money::add($spentBefore, $amount);

        return $extra + [
            'budget' => $budget,
            'spent_before' => $spentBefore,
            'spent_after' => $spentAfter,
            'remaining_before' => Money::sub($budget, $spentBefore),
            'remaining_after' => Money::sub($budget, $spentAfter),
            'over_by_after' => Money::floorAtZero(Money::sub($spentAfter, $budget)),
            'percentage_after' => Money::percentage($spentAfter, $budget),
            'status_before' => $this->budgets->statusFor($spentBefore, $budget)->value,
            'status_after' => $this->budgets->statusFor($spentAfter, $budget)->value,
            'days_remaining' => $summary['days_remaining'] ?? null,
            // What each remaining day is worth once this expense is counted.
            'daily_limit_after' => ($summary['days_remaining'] ?? 0) > 0
                ? Money::div(
                    Money::floorAtZero(Money::sub($budget, $spentAfter)),
                    (string) $summary['days_remaining'],
                )
                : '0.00',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function categoryImpact(
        \App\Models\MonthlyPlan $plan,
        ?int $categoryId,
        string $amount,
        string $existing,
    ): ?array {
        if ($categoryId === null) {
            return null;
        }

        $summary = collect($this->budgets->categorySummaries($plan))
            ->firstWhere('category_id', $categoryId);

        if ($summary === null || ! $summary['has_budget']) {
            return null;
        }

        $budget = Money::of($summary['budget']);
        $spentBefore = Money::floorAtZero(Money::sub($summary['spent'], $existing));
        $spentAfter = Money::add($spentBefore, $amount);
        $warnAt = (int) $summary['warning_percentage'];

        return [
            'category_id' => $categoryId,
            'name' => $summary['name'],
            'budget' => $budget,
            'spent_before' => $spentBefore,
            'spent_after' => $spentAfter,
            'remaining_after' => Money::sub($budget, $spentAfter),
            'over_by_after' => Money::floorAtZero(Money::sub($spentAfter, $budget)),
            'percentage_after' => Money::percentage($spentAfter, $budget),
            'status_before' => $this->budgets->statusFor($spentBefore, $budget, $warnAt)->value,
            'status_after' => $this->budgets->statusFor($spentAfter, $budget, $warnAt)->value,
        ];
    }

    private function existingAmount(User $user, ?int $expenseId): string
    {
        if ($expenseId === null) {
            return '0.00';
        }

        return Money::of(
            $user->expenses()->whereKey($expenseId)->value('amount') ?? 0
        );
    }

    /**
     * @param  array<string, mixed>|null  $week
     */
    private function headline(?array $week, bool $willExceed, bool $alreadyOver): string
    {
        if ($week === null) {
            return 'This falls outside your planned weeks.';
        }

        if ($willExceed) {
            return 'This puts you LKR '.number_format((float) $week['over_by_after'], 2)
                .' over your week '.$week['week_number'].' budget.';
        }

        if ($alreadyOver) {
            return 'You are already over week '.$week['week_number']
                .' by LKR '.number_format((float) $week['over_by_after'], 2).'.';
        }

        return 'LKR '.number_format((float) $week['remaining_after'], 2)
            .' would be left for the rest of week '.$week['week_number'].'.';
    }

    /** @return array<string, mixed> */
    private function noPlanResult(string $amount): array
    {
        return [
            'amount' => $amount,
            'has_plan' => false,
            'week' => null,
            'month' => null,
            'category' => null,
            'allowance' => null,
            'buffer_remaining' => '0.00',
            'will_exceed_week' => false,
            'already_over_week' => false,
            'will_exceed_category' => false,
            'needs_decision' => false,
            'headline' => 'No active plan, so this is not measured against a budget.',
        ];
    }
}
