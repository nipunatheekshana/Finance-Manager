<?php

namespace App\Services;

use App\Enums\BudgetStatus;
use App\Models\Category;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\WeeklyBudget;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The single source of truth for every budget figure in the application:
 * monthly, weekly, daily and per-category. Nothing else recomputes these.
 *
 * "Spent" against a spending budget means discretionary spending only — bills
 * logged against a recurring transaction are budgeted separately in the plan
 * and are excluded so the same money is never charged twice.
 */
class BudgetCalculationService
{
    public function __construct(private readonly BudgetCycleService $cycles) {}

    /**
     * Day-to-day spend inside a date window.
     *
     * Categories with an allowance are excluded: that money was already
     * reserved out of income when the plan was built, so counting it against
     * the weekly pool as well would charge the user twice for it.
     *
     * @param  list<int>  $excludeCategoryIds
     */
    public function spentBetween(
        int $userId,
        CarbonInterface $start,
        CarbonInterface $end,
        array $excludeCategoryIds = [],
    ): string {
        return Money::of(
            Expense::query()
                ->where('user_id', $userId)
                ->discretionary()
                ->when($excludeCategoryIds !== [], fn ($q) => $q->whereNotIn('category_id', $excludeCategoryIds))
                ->between($start->toDateString(), $end->toDateString())
                ->sum('amount')
        );
    }

    /**
     * @param  list<int>  $excludeCategoryIds
     */
    public function spentOn(int $userId, CarbonInterface $date, array $excludeCategoryIds = []): string
    {
        return Money::of(
            Expense::query()
                ->where('user_id', $userId)
                ->discretionary()
                ->when($excludeCategoryIds !== [], fn ($q) => $q->whereNotIn('category_id', $excludeCategoryIds))
                ->onDate($date->toDateString())
                ->sum('amount')
        );
    }

    /**
     * What this plan set aside per category.
     *
     * @return array<int, string>
     */
    public function allowanceAmounts(MonthlyPlan $plan): array
    {
        $plan->loadMissing('budgetCategories');

        return $plan->budgetCategories
            ->filter(fn ($row) => (bool) $row->is_allowance)
            ->mapWithKeys(fn ($row) => [(int) $row->category_id => Money::of($row->budget_amount)])
            ->all();
    }

    /**
     * What is left of one category's allowance for the cycle.
     *
     * Returns null when the category is not an allowance in this plan, which
     * is the signal that ordinary day-to-day rules apply.
     *
     * @return array{category_id: int, allocated: string, spent: string, remaining: string}|null
     */
    public function allowanceStateFor(
        MonthlyPlan $plan,
        ?int $categoryId,
        string $exclude = '0.00',
    ): ?array {
        if ($categoryId === null) {
            return null;
        }

        $allocated = $this->allowanceAmounts($plan)[$categoryId] ?? null;

        if ($allocated === null) {
            return null;
        }

        $spend = $this->categorySpend(
            $plan->user_id,
            [$categoryId],
            $plan->cycle_start_date,
            $plan->cycle_end_date,
        );

        // The expense being edited is already in the total; take it back out.
        $spent = Money::floorAtZero(Money::sub($spend[$categoryId] ?? '0.00', $exclude));

        return [
            'category_id' => $categoryId,
            'allocated' => $allocated,
            'spent' => $spent,
            'remaining' => Money::floorAtZero(Money::sub($allocated, $spent)),
        ];
    }

    /** The display name of an allowance category in this plan. */
    public function allowanceNameFor(MonthlyPlan $plan, int $categoryId): string
    {
        $plan->loadMissing('budgetCategories.category');

        return $plan->budgetCategories
            ->firstWhere('category_id', $categoryId)?->category?->name ?? 'allowance';
    }

    /**
     * Day-to-day spend in a window, with allowance spending netted off.
     *
     * An allowance covers spending in its category **up to the amount set
     * aside** — that money already came out of income when the plan was built.
     * Anything past it is not covered by anything, so it spills into the
     * day-to-day budget like ordinary spending would.
     *
     * The "already used earlier in the cycle" part matters: if week 1 used the
     * whole fuel allowance, week 2 has none left and all of week 2's fuel
     * counts.
     */
    public function discretionarySpentBetween(
        MonthlyPlan $plan,
        CarbonInterface $start,
        CarbonInterface $end,
    ): string {
        $total = $this->spentBetween($plan->user_id, $start, $end);
        $allowances = $this->allowanceAmounts($plan);

        if ($allowances === []) {
            return $total;
        }

        $categoryIds = array_keys($allowances);
        $cycleStart = CarbonImmutable::instance($plan->cycle_start_date)->startOfDay();
        $windowStart = CarbonImmutable::instance($start)->startOfDay();

        $inWindow = $this->categorySpend($plan->user_id, $categoryIds, $start, $end);

        $before = $windowStart->lte($cycleStart)
            ? []
            : $this->categorySpend($plan->user_id, $categoryIds, $cycleStart, $windowStart->subDay());

        $covered = '0.00';

        foreach ($allowances as $categoryId => $amount) {
            $spentInWindow = Money::of($inWindow[$categoryId] ?? 0);
            $stillAvailable = Money::floorAtZero(Money::sub($amount, Money::of($before[$categoryId] ?? 0)));

            $covered = Money::add($covered, Money::min($spentInWindow, $stillAvailable));
        }

        return Money::floorAtZero(Money::sub($total, $covered));
    }

    /**
     * Day-to-day spend on one day, with allowance spending netted off.
     */
    public function discretionarySpentOn(MonthlyPlan $plan, CarbonInterface $date): string
    {
        return $this->discretionarySpentBetween($plan, $date, $date);
    }

    /**
     * @param  list<int>  $categoryIds
     * @return array<int, string>
     */
    private function categorySpend(int $userId, array $categoryIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return Expense::query()
            ->where('user_id', $userId)
            ->discretionary()
            ->whereIn('category_id', $categoryIds)
            ->between($start->toDateString(), $end->toDateString())
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id')
            ->mapWithKeys(fn ($total, $id) => [(int) $id => (string) $total])
            ->all();
    }

    /**
     * Month-level view of the plan's spending budget.
     *
     * @return array<string, mixed>
     */
    public function monthlySummary(MonthlyPlan $plan, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();

        $budget = Money::of($plan->spending_budget);
        $spent = $this->discretionarySpentBetween($plan, $plan->cycle_start_date, $plan->cycle_end_date);
        $remaining = Money::sub($budget, $spent);

        $daysRemaining = $this->cycles->remainingDays($today, $plan->cycle_end_date);

        return [
            'label' => $plan->label(),
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $remaining,
            'percentage_used' => Money::percentage($spent, $budget),
            'status' => $this->statusFor($spent, $budget)->value,
            'over_by' => Money::floorAtZero(Money::sub($spent, $budget)),
            'days_remaining' => $daysRemaining,
            'cycle_start' => $plan->cycle_start_date->toDateString(),
            'cycle_end' => $plan->cycle_end_date->toDateString(),
            'buffer' => Money::of($plan->buffer),
            'buffer_used' => Money::of($plan->buffer_used),
            'buffer_remaining' => $plan->bufferRemaining(),
        ];
    }

    /**
     * Week-level view, including the recommended daily limit derived from the
     * real number of calendar days left in the week.
     *
     * @return array<string, mixed>
     */
    public function weeklySummary(WeeklyBudget $week, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();

        $week->loadMissing('monthlyPlan');

        $budget = $week->effectiveBudget();
        $spent = $this->discretionarySpentBetween($week->monthlyPlan, $week->start_date, $week->end_date);
        $remaining = Money::sub($budget, $spent);

        $start = CarbonImmutable::instance($week->start_date)->startOfDay();
        $end = CarbonImmutable::instance($week->end_date)->startOfDay();

        $totalDays = $start->diffInDays($end) + 1;
        $isCurrent = $today->betweenIncluded($start, $end);

        // Days left counts today. Past weeks have none; future weeks have all.
        $daysRemaining = match (true) {
            $today->gt($end) => 0,
            $today->lt($start) => $totalDays,
            default => $this->cycles->remainingDays($today, $end),
        };

        $recommendedDaily = $daysRemaining > 0
            ? Money::div(Money::floorAtZero($remaining), (string) $daysRemaining)
            : '0.00';

        return [
            'id' => $week->id,
            'week_number' => $week->week_number,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'budget' => $budget,
            'original_budget' => Money::of($week->budget_amount),
            'was_adjusted' => $week->adjusted_amount !== null,
            'spent' => $spent,
            'remaining' => $remaining,
            'percentage_used' => Money::percentage($spent, $budget),
            'status' => $this->statusFor($spent, $budget)->value,
            'over_by' => Money::floorAtZero(Money::sub($spent, $budget)),
            'days_total' => $totalDays,
            'days_remaining' => $daysRemaining,
            'recommended_daily' => $recommendedDaily,
            'is_current' => $isCurrent,
            'is_past' => $today->gt($end),
        ];
    }

    /**
     * Today's allowance.
     *
     * The recommendation is the pace the user could have set at the start of
     * today, capped by the pace the rest of the month can sustain. It is
     * recalculated on every read, so logging an expense immediately tightens
     * tomorrow's number.
     *
     * @return array<string, mixed>
     */
    public function dailySummary(MonthlyPlan $plan, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();

        $spentToday = $this->discretionarySpentOn($plan, $today);
        $week = $this->currentWeek($plan, $today);

        $monthly = $this->monthlySummary($plan, $today);
        $monthDaysRemaining = max(1, (int) $monthly['days_remaining']);
        $monthlyPace = Money::div(Money::floorAtZero($monthly['remaining']), (string) $monthDaysRemaining);

        if ($week === null) {
            // Outside any planned week — fall back to the month-wide pace.
            $recommended = $monthlyPace;
            $daysRemaining = $monthDaysRemaining;
            $weekRemaining = $monthly['remaining'];
        } else {
            $weekSummary = $this->weeklySummary($week, $today);
            $daysRemaining = max(1, (int) $weekSummary['days_remaining']);
            $weekRemaining = $weekSummary['remaining'];

            // Start-of-day remaining, so the figure does not shrink as the user
            // spends within the same day — "Remaining today" carries that.
            $startOfDayRemaining = Money::add($weekRemaining, $spentToday);
            $weeklyPace = Money::div(Money::floorAtZero($startOfDayRemaining), (string) $daysRemaining);

            $recommended = Money::min($weeklyPace, $monthlyPace);
        }

        $daysAfterToday = max(0, $daysRemaining - 1);
        $nextDayRecommended = $daysAfterToday > 0
            ? Money::div(Money::floorAtZero($weekRemaining), (string) $daysAfterToday)
            : Money::floorAtZero($weekRemaining);

        return [
            'date' => $today->toDateString(),
            'spent' => $spentToday,
            'recommended' => $recommended,
            'remaining' => Money::sub($recommended, $spentToday),
            'percentage_used' => Money::percentage($spentToday, $recommended),
            'status' => $this->statusFor($spentToday, $recommended)->value,
            'over_by' => Money::floorAtZero(Money::sub($spentToday, $recommended)),
            // What each remaining day is worth once today is closed out.
            'next_day_recommended' => $nextDayRecommended,
            'days_remaining_in_week' => $daysRemaining,
        ];
    }

    /**
     * The weekly budget that contains $today, if any.
     */
    public function currentWeek(MonthlyPlan $plan, ?CarbonInterface $today = null): ?WeeklyBudget
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();

        $plan->loadMissing('weeklyBudgets');

        return $plan->weeklyBudgets
            ->first(fn (WeeklyBudget $week) => $week->containsDate($today));
    }

    /**
     * Per-category spending against the user's monthly category limits.
     *
     * Unlike the spending budget, this counts every expense in the category —
     * a category limit is about total spending in that category.
     *
     * @return list<array<string, mixed>>
     */
    public function categorySummaries(MonthlyPlan $plan): array
    {
        $spendByCategory = Expense::query()
            ->where('user_id', $plan->user_id)
            ->between($plan->cycle_start_date->toDateString(), $plan->cycle_end_date->toDateString())
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        // A plan-specific budget overrides the category's standing limit.
        $planBudgets = $plan->budgetCategories()->pluck('budget_amount', 'category_id');

        $categories = Category::query()
            ->where('user_id', $plan->user_id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $categories->map(function (Category $category) use ($spendByCategory, $planBudgets) {
            $spent = Money::of($spendByCategory[$category->id] ?? 0);
            $budget = Money::of($planBudgets[$category->id] ?? $category->monthly_budget ?? 0);
            $hasBudget = Money::isPositive($budget);

            return [
                'category_id' => $category->id,
                'name' => $category->name,
                'icon' => $category->icon,
                'color' => $category->color,
                'has_budget' => $hasBudget,
                'budget' => $budget,
                'spent' => $spent,
                'remaining' => Money::sub($budget, $spent),
                'percentage_used' => Money::percentage($spent, $budget),
                'warning_percentage' => $category->warning_percentage,
                'status' => $hasBudget
                    ? $this->statusFor($spent, $budget, $category->warning_percentage)->value
                    : BudgetStatus::Safe->value,
            ];
        })->all();
    }

    /**
     * Each allowance: what was set aside, what has gone, and whether it is
     * being spent faster than the cycle is passing.
     *
     * Pace is the point of this view. An allowance is spent gradually, so
     * "60% gone" means nothing on its own — it is fine on day 20 of 30 and a
     * problem on day 5.
     *
     * @return list<array<string, mixed>>
     */
    public function allowanceSummaries(MonthlyPlan $plan, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();
        $plan->loadMissing('budgetCategories.category');

        $start = CarbonImmutable::instance($plan->cycle_start_date)->startOfDay();
        $end = CarbonImmutable::instance($plan->cycle_end_date)->startOfDay();

        $totalDays = max(1, $start->diffInDays($end) + 1);
        $elapsedDays = min($totalDays, max(1, $start->diffInDays($today) + 1));
        $daysRemaining = $this->cycles->remainingDays($today, $end);


        $spendByCategory = Expense::query()
            ->where('user_id', $plan->user_id)
            ->between($plan->cycle_start_date->toDateString(), $plan->cycle_end_date->toDateString())
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        return $plan->budgetCategories
            ->filter(fn ($row) => (bool) $row->is_allowance)
            ->map(function ($row) use ($spendByCategory, $elapsedDays, $totalDays, $daysRemaining) {
                $allocated = Money::of($row->budget_amount);
                $spent = Money::of($spendByCategory[$row->category_id] ?? 0);
                $remaining = Money::sub($allocated, $spent);

                // What an even spend across the cycle would have used by now.
                // Multiply before dividing: rounding the elapsed fraction first
                // put 30,000 over two days of thirty at 2,000.01 instead of
                // 2,000.00.
                $expectedByNow = Money::div(
                    Money::mul($allocated, (string) $elapsedDays),
                    (string) $totalDays,
                );
                $warnAt = $row->category?->warning_percentage ?? 80;

                return [
                    'category_id' => (int) $row->category_id,
                    'name' => $row->category?->name ?? 'Category',
                    'icon' => $row->category?->icon ?? 'circle',
                    'color' => $row->category?->color ?? 'slate',
                    'allocated' => $allocated,
                    'spent' => $spent,
                    'remaining' => $remaining,
                    'percentage_used' => Money::percentage($spent, $allocated),
                    'status' => $this->statusFor($spent, $allocated, $warnAt)->value,
                    'over_by' => Money::floorAtZero(Money::sub($spent, $allocated)),
                    'days_remaining' => $daysRemaining,
                    // What is left, spread over the days that are left.
                    'daily_allowance' => $daysRemaining > 0
                        ? Money::div(Money::floorAtZero($remaining), (string) $daysRemaining)
                        : '0.00',
                    'expected_by_now' => $expectedByNow,
                    // Ahead of pace means it will run out before the cycle does.
                    'ahead_of_pace' => Money::gt($spent, $expectedByNow),
                    'pace_difference' => Money::sub($spent, $expectedByNow),
                ];
            })
            ->sortByDesc('percentage_used')
            ->values()
            ->all();
    }

    /**
     * All weeks of a plan, summarised.
     *
     * @return list<array<string, mixed>>
     */
    public function weeklySummaries(MonthlyPlan $plan, ?CarbonInterface $today = null): array
    {
        $plan->loadMissing('weeklyBudgets');

        return $plan->weeklyBudgets
            ->map(fn (WeeklyBudget $week) => $this->weeklySummary($week, $today))
            ->values()
            ->all();
    }

    /**
     * Refresh the cached spent_amount on each week. Expenses stay the source of
     * truth; this only keeps list views cheap.
     */
    public function refreshWeeklySpend(MonthlyPlan $plan): void
    {
        $plan->loadMissing('weeklyBudgets');

        foreach ($plan->weeklyBudgets as $week) {
            $week->forceFill([
                'spent_amount' => $this->discretionarySpentBetween($plan, $week->start_date, $week->end_date),
            ])->save();
        }
    }

    /**
     * Weeks that are finished and were overspent, newest first.
     *
     * @return Collection<int, WeeklyBudget>
     */
    public function overspentWeeks(MonthlyPlan $plan, ?CarbonInterface $today = null): Collection
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();

        $plan->loadMissing('weeklyBudgets');

        return $plan->weeklyBudgets->filter(function (WeeklyBudget $week) use ($plan, $today) {
            $spent = $this->discretionarySpentBetween($plan, $week->start_date, $week->end_date);

            return Money::gt($spent, $week->effectiveBudget())
                && CarbonImmutable::instance($week->start_date)->lte($today);
        })->values();
    }

    /**
     * Safe / warning / over, using the category's own warning threshold when one
     * is supplied. A zero budget is never "over" — there is nothing to exceed.
     */
    public function statusFor(string $spent, string $budget, int $warningPercentage = 80): BudgetStatus
    {
        if (! Money::isPositive($budget)) {
            return BudgetStatus::Safe;
        }

        if (Money::gt($spent, $budget)) {
            return BudgetStatus::Over;
        }

        return Money::percentage($spent, $budget) >= $warningPercentage
            ? BudgetStatus::Warning
            : BudgetStatus::Safe;
    }
}
