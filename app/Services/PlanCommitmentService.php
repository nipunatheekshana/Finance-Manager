<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\PlanDebtAllocation;
use App\Models\PlanSavingsAllocation;
use App\Models\SavingsGoal;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Taking on a new commitment part-way through a cycle.
 *
 * A debt opened on the 10th is real money that has to be paid this month, but
 * the plan was balanced without it. Something else has to give, and the user
 * picks what — the same rule as every other change: nothing moves on its own.
 *
 * Exactly one source gives up the money, which keeps the arithmetic legible:
 *
 *   spending  the day-to-day pool shrinks, and the weeks left are re-cut
 *   buffer    the safety net shrinks; weekly budgets are untouched
 *   savings   a goal gets less this month; spending is untouched
 *   debts     another debt is paid down more slowly this month
 */
class PlanCommitmentService
{
    /** Where the money for a new commitment comes from. */
    public const SOURCES = ['spending', 'buffer', 'savings', 'savings_withdrawal', 'debts'];

    /**
     * Topping up an allowance can also come out of another allowance, which a
     * new debt cannot: two pots of the same kind, one handing over to the
     * other.
     */
    public const TOP_UP_SOURCES = ['spending', 'buffer', 'savings', 'savings_withdrawal', 'allowance'];

    public function __construct(
        private readonly FinancialPlanService $plans,
        private readonly BudgetCalculationService $budgets,
        private readonly SavingsService $savings,
        private readonly AuditService $audit,
    ) {}

    /**
     * Debts with no allocation in this plan.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingDebts(MonthlyPlan $plan): array
    {
        $plan->loadMissing('debtAllocations');
        $allocated = $plan->debtAllocations->pluck('debt_id')->all();

        return $plan->user->debts()
            ->active()
            ->whereNotIn('id', $allocated ?: [0])
            ->orderBy('name')
            ->get()
            ->map(fn (Debt $debt) => [
                'debt_id' => $debt->id,
                'name' => $debt->name,
                'type_label' => $debt->type->label(),
                'current_balance' => Money::of($debt->current_balance),
                'due_day' => $debt->due_day,
                // What the plan would ask for, if the user accepts it.
                'suggested_amount' => Money::isPositive($debt->planned_payment)
                    ? Money::of($debt->planned_payment)
                    : Money::of($debt->minimum_payment),
                'minimum_payment' => Money::of($debt->minimum_payment),
                'created_at' => $debt->created_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Each way of paying for it, with the figure it would leave behind.
     *
     * @return array<string, mixed>
     */
    public function optionsFor(MonthlyPlan $plan, Debt $debt, ?string $amount = null): array
    {
        $amount = Money::of($amount ?? (Money::isPositive($debt->planned_payment)
            ? $debt->planned_payment
            : $debt->minimum_payment));

        $spending = Money::of($plan->spending_budget);
        $buffer = $plan->bufferRemaining();
        $fromSavings = $this->reducibleSavings($plan);
        $reclaimable = $this->reclaimableSavings($plan);
        $fromDebts = $this->reducibleDebts($plan);

        return [
            'plan_label' => $plan->label(),
            'debt' => [
                'debt_id' => $debt->id,
                'name' => $debt->name,
                'current_balance' => Money::of($debt->current_balance),
            ],
            'amount' => $amount,
            'options' => [
                [
                    'source' => 'spending',
                    'label' => 'Take it from day-to-day money',
                    'description' => 'Your weekly budgets for the rest of the cycle get smaller.',
                    'available' => Money::gte($spending, $amount),
                    'unavailable_reason' => Money::gte($spending, $amount)
                        ? null
                        : 'Only '.$spending.' is left to spend this cycle.',
                    'current' => $spending,
                    'resulting_spending_budget' => Money::sub($spending, $amount),
                    'weeks_affected' => count($this->remainingWeeks($plan)),
                ],
                [
                    'source' => 'buffer',
                    'label' => 'Take it from the buffer',
                    'description' => 'Your safety net shrinks. Weekly budgets stay as they are.',
                    'available' => Money::gte($buffer, $amount),
                    'unavailable_reason' => Money::gte($buffer, $amount)
                        ? null
                        : 'Only '.$buffer.' of buffer is left.',
                    'current' => $buffer,
                    'resulting_buffer' => Money::sub($buffer, $amount),
                ],
                [
                    'source' => 'savings',
                    'label' => 'Save less this month',
                    'description' => 'Lowest-priority goal first, never below what is already put aside.',
                    'available' => Money::gte($fromSavings, $amount),
                    'unavailable_reason' => Money::gte($fromSavings, $amount)
                        ? null
                        : 'Only '.$fromSavings.' of this month\'s savings can still be moved.',
                    'current' => $fromSavings,
                    'resulting_savings' => Money::floorAtZero(Money::sub($plan->savings, $amount)),
                ],
                [
                    'source' => 'savings_withdrawal',
                    'label' => "Take back this month's saving",
                    'description' => 'Moves money you have already put into a goal back out again.',
                    'available' => Money::gte($reclaimable, $amount),
                    'unavailable_reason' => Money::gte($reclaimable, $amount)
                        ? null
                        : ($reclaimable === '0.00'
                            ? 'Nothing has been put aside this cycle to take back.'
                            : 'Only '.$reclaimable.' was put aside this cycle.'),
                    'current' => $reclaimable,
                    'resulting_savings' => Money::floorAtZero(Money::sub($plan->savings, $amount)),
                ],
                [
                    'source' => 'debts',
                    'label' => 'Pay another debt more slowly',
                    'description' => 'Lowest-interest debt first, never below what is already paid.',
                    'available' => Money::gte($fromDebts, $amount),
                    'unavailable_reason' => Money::gte($fromDebts, $amount)
                        ? null
                        : 'Only '.$fromDebts.' of this month\'s debt payments can still be moved.',
                    'current' => $fromDebts,
                    'resulting_debt_payment' => Money::of($plan->debt_payment),
                ],
            ],
        ];
    }

    /**
     * Add the debt to the plan and take the money from the chosen source.
     *
     * @return array<string, mixed>
     */
    public function add(MonthlyPlan $plan, Debt $debt, string $amount, string $source, ?string $reason = null): array
    {
        $amount = Money::of($amount);

        if (! Money::isPositive($amount)) {
            throw new RuntimeException('Enter an amount greater than zero.');
        }

        if (! in_array($source, self::SOURCES, true)) {
            throw new RuntimeException('Choose where the money should come from.');
        }

        if ($plan->debtAllocations()->where('debt_id', $debt->id)->exists()) {
            throw new RuntimeException($debt->name.' is already in this plan.');
        }

        $option = collect($this->optionsFor($plan, $debt, $amount)['options'])
            ->firstWhere('source', $source);

        if ($option === null || ! $option['available']) {
            throw new RuntimeException(
                $option['unavailable_reason'] ?? 'That is not enough to cover the payment.'
            );
        }

        return DB::transaction(function () use ($plan, $debt, $amount, $source, $reason) {
            $before = [
                'spending_budget' => Money::of($plan->spending_budget),
                'buffer' => Money::of($plan->buffer),
                'savings' => Money::of($plan->savings),
                'debt_payment' => Money::of($plan->debt_payment),
            ];

            $plan->debtAllocations()->create([
                'debt_id' => $debt->id,
                'planned_amount' => $amount,
                'paid_amount' => '0.00',
            ]);

            match ($source) {
                // Nothing else to move: the allocation itself reduces the pool,
                // and the weeks are re-cut below.
                'spending' => null,
                'buffer' => $plan->forceFill([
                    'buffer' => Money::floorAtZero(Money::sub($plan->buffer, $amount)),
                ])->save(),
                'savings' => $this->takeFromSavings($plan, $amount),
                'savings_withdrawal' => $this->reclaimFromSavings($plan, $amount, $debt->name),
                'debts' => $this->takeFromDebts($plan, $amount, $debt->id),
            };

            $plan = $this->plans->recalculate($plan->fresh());

            // Only the day-to-day pool changes the weeks. The other sources
            // leave the spending budget where it was.
            if ($source === 'spending') {
                $this->reduceRemainingWeeks($plan, $amount);
            }

            $this->audit->record(
                $plan->user_id,
                'plan.debt_added',
                $plan,
                $before,
                [
                    'debt' => $debt->name,
                    'amount' => $amount,
                    'source' => $source,
                    'spending_budget' => Money::of($plan->spending_budget),
                ],
                $reason,
            );

            return [
                'plan' => $plan->fresh(),
                'amount' => $amount,
                'source' => $source,
            ];
        });
    }

    /**
     * Money already in a goal that this cycle put there — and that the goal
     * still holds. Capped by the balance, because a goal cannot give back more
     * than it has, whenever the money arrived.
     */
    private function reclaimableSavings(MonthlyPlan $plan): string
    {
        $plan->loadMissing('savingsAllocations.savingsGoal');

        return Money::sum($plan->savingsAllocations->map(
            fn (PlanSavingsAllocation $row) => Money::min(
                Money::of($row->saved_amount),
                Money::of($row->savingsGoal?->current_amount ?? 0),
            )
        ));
    }

    /**
     * Take this cycle's saving back out of the goals and let it pay for the
     * new commitment instead. The plan intends to save less, so the day-to-day
     * budget is unaffected.
     */
    private function reclaimFromSavings(MonthlyPlan $plan, string $amount, string $debtName): void
    {
        $left = $amount;

        $allocations = $plan->savingsAllocations()
            ->with('savingsGoal')
            ->get()
            ->sortByDesc(fn (PlanSavingsAllocation $row) => $row->savingsGoal->priority);

        foreach ($allocations as $allocation) {
            if (! Money::isPositive($left)) {
                break;
            }

            $goal = $allocation->savingsGoal;

            $available = Money::min(
                Money::of($allocation->saved_amount),
                Money::of($goal->current_amount),
            );
            $take = Money::min($left, $available);

            if (! Money::isPositive($take)) {
                continue;
            }

            // A real withdrawal: the goal's balance drops and the movement is
            // on its history, so the money is never quietly conjured.
            $this->savings->withdraw($goal, [
                'amount' => $take,
                'transaction_date' => CarbonImmutable::today()->toDateString(),
                'monthly_plan_id' => $plan->id,
                'description' => 'Moved to '.$debtName,
            ]);

            $allocation->refresh()->forceFill([
                'planned_amount' => Money::floorAtZero(
                    Money::sub($allocation->planned_amount, $take)
                ),
            ])->save();

            $left = Money::sub($left, $take);
        }
    }

    /**
     * Allowances that have been spent past the amount reserved for them.
     *
     * @return list<array<string, mixed>>
     */
    public function overspentAllowances(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        return collect($this->budgets->allowanceSummaries($plan, $today))
            ->filter(fn (array $row) => Money::isPositive($row['over_by']))
            ->map(fn (array $row) => [
                'category_id' => $row['category_id'],
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'allocated' => $row['allocated'],
                'spent' => $row['spent'],
                'over_by' => $row['over_by'],
            ])
            ->values()
            ->all();
    }

    /**
     * Each way of covering an allowance that has run out.
     *
     * @return array<string, mixed>
     */
    public function topUpOptionsFor(MonthlyPlan $plan, int $categoryId, ?string $amount = null): array
    {
        $state = $this->budgets->allowanceStateFor($plan, $categoryId);

        if ($state === null) {
            throw new RuntimeException('That category is not an allowance in this plan.');
        }

        $summary = collect($this->budgets->allowanceSummaries($plan))
            ->firstWhere('category_id', $categoryId);

        $amount = Money::of($amount ?? ($summary['over_by'] ?? '0.00'));

        $spending = Money::of($plan->spending_budget);
        $buffer = $plan->bufferRemaining();
        $fromSavings = $this->reducibleSavings($plan);
        $reclaimable = $this->reclaimableSavings($plan);
        $others = $this->otherAllowances($plan, $categoryId);
        $fromAllowances = Money::sum(array_column($others, 'available'));

        return [
            'plan_label' => $plan->label(),
            'category' => [
                'category_id' => $categoryId,
                'name' => $summary['name'] ?? 'Allowance',
                'allocated' => $state['allocated'],
                'spent' => $state['spent'],
                'over_by' => $summary['over_by'] ?? '0.00',
            ],
            'amount' => $amount,
            'other_allowances' => $others,
            'options' => [
                [
                    'source' => 'allowance',
                    'label' => 'Move it from another allowance',
                    'description' => 'One pot hands over to the other. Nothing else in the plan changes.',
                    'available' => Money::gte($fromAllowances, $amount),
                    'unavailable_reason' => Money::gte($fromAllowances, $amount)
                        ? null
                        : ($others === []
                            ? 'You have no other allowance to move it from.'
                            : 'Only '.$fromAllowances.' can be moved out of your other allowances.'),
                    'current' => $fromAllowances,
                    'needs_choice' => true,
                ],
                [
                    'source' => 'spending',
                    'label' => 'Take it from day-to-day money',
                    'description' => 'This is what already happens if you leave it — reserving it makes it deliberate.',
                    'available' => Money::gte($spending, $amount),
                    'unavailable_reason' => Money::gte($spending, $amount)
                        ? null
                        : 'Only '.$spending.' is left to spend this cycle.',
                    'current' => $spending,
                    'resulting_spending_budget' => Money::sub($spending, $amount),
                ],
                [
                    'source' => 'buffer',
                    'label' => 'Take it from the buffer',
                    'description' => 'Your safety net shrinks. Weekly budgets stay as they are.',
                    'available' => Money::gte($buffer, $amount),
                    'unavailable_reason' => Money::gte($buffer, $amount)
                        ? null
                        : 'Only '.$buffer.' of buffer is left.',
                    'current' => $buffer,
                    'resulting_buffer' => Money::sub($buffer, $amount),
                ],
                [
                    'source' => 'savings',
                    'label' => 'Save less this month',
                    'description' => 'Only money not yet moved into a goal.',
                    'available' => Money::gte($fromSavings, $amount),
                    'unavailable_reason' => Money::gte($fromSavings, $amount)
                        ? null
                        : 'Only '.$fromSavings.' of this month\'s savings can still be moved.',
                    'current' => $fromSavings,
                    'resulting_savings' => Money::floorAtZero(Money::sub($plan->savings, $amount)),
                ],
                [
                    'source' => 'savings_withdrawal',
                    'label' => "Take back this month's saving",
                    'description' => 'Moves money you have already put into a goal back out again.',
                    'available' => Money::gte($reclaimable, $amount),
                    'unavailable_reason' => Money::gte($reclaimable, $amount)
                        ? null
                        : ($reclaimable === '0.00'
                            ? 'Nothing has been put aside this cycle to take back.'
                            : 'Only '.$reclaimable.' was put aside this cycle.'),
                    'current' => $reclaimable,
                    'resulting_savings' => Money::floorAtZero(Money::sub($plan->savings, $amount)),
                ],
            ],
        ];
    }

    /**
     * Add to an allowance and take the money from the chosen source. Both
     * figures move together, so the plan still adds up afterwards.
     *
     * @return array<string, mixed>
     */
    public function topUpAllowance(
        MonthlyPlan $plan,
        int $categoryId,
        string $amount,
        string $source,
        ?int $fromCategoryId = null,
        ?string $reason = null,
    ): array {
        $amount = Money::of($amount);

        if (! Money::isPositive($amount)) {
            throw new RuntimeException('Enter an amount greater than zero.');
        }

        if (! in_array($source, self::TOP_UP_SOURCES, true)) {
            throw new RuntimeException('Choose where the money should come from.');
        }

        $row = $plan->budgetCategories()
            ->where('category_id', $categoryId)
            ->where('is_allowance', true)
            ->first();

        if ($row === null) {
            throw new RuntimeException('That category is not an allowance in this plan.');
        }

        $option = collect($this->topUpOptionsFor($plan, $categoryId, $amount)['options'])
            ->firstWhere('source', $source);

        if ($option === null || ! $option['available']) {
            throw new RuntimeException(
                $option['unavailable_reason'] ?? 'That is not enough to cover it.'
            );
        }

        return DB::transaction(function () use ($plan, $row, $categoryId, $amount, $source, $fromCategoryId, $reason) {
            $before = Money::of($row->budget_amount);

            if ($source === 'allowance') {
                $this->takeFromAllowance($plan, $amount, $categoryId, $fromCategoryId);
            }

            $row->forceFill(['budget_amount' => Money::add($before, $amount)])->save();

            match ($source) {
                // The allowance total grows, so the weekly pool shrinks by
                // itself; the weeks are re-cut below.
                'spending', 'allowance' => null,
                'buffer' => $plan->forceFill([
                    'buffer' => Money::floorAtZero(Money::sub($plan->buffer, $amount)),
                ])->save(),
                'savings' => $this->takeFromSavings($plan, $amount),
                'savings_withdrawal' => $this->reclaimFromSavings(
                    $plan,
                    $amount,
                    ($row->category?->name ?? 'an allowance').' allowance',
                ),
            };

            $plan = $this->plans->recalculate($plan->fresh());

            if ($source === 'spending') {
                $this->reduceRemainingWeeks($plan, $amount);
            }

            $this->audit->record(
                $plan->user_id,
                'plan.allowance_topped_up',
                $plan,
                ['allowance' => $before],
                [
                    'category_id' => $categoryId,
                    'allowance' => Money::add($before, $amount),
                    'amount' => $amount,
                    'source' => $source,
                    'from_category_id' => $fromCategoryId,
                ],
                $reason,
            );

            return [
                'plan' => $plan->fresh(),
                'amount' => $amount,
                'source' => $source,
            ];
        });
    }

    /**
     * The allowances that could hand money over, and how much each can spare
     * without dipping below what it has already spent.
     *
     * @return list<array<string, mixed>>
     */
    private function otherAllowances(MonthlyPlan $plan, int $exceptCategoryId): array
    {
        return collect($this->budgets->allowanceSummaries($plan))
            ->reject(fn (array $row) => $row['category_id'] === $exceptCategoryId)
            ->map(fn (array $row) => [
                'category_id' => $row['category_id'],
                'name' => $row['name'],
                'allocated' => $row['allocated'],
                'spent' => $row['spent'],
                // Money already spent cannot be handed over.
                'available' => Money::floorAtZero(Money::sub($row['allocated'], $row['spent'])),
            ])
            ->filter(fn (array $row) => Money::isPositive($row['available']))
            ->values()
            ->all();
    }

    private function takeFromAllowance(
        MonthlyPlan $plan,
        string $amount,
        int $toCategoryId,
        ?int $fromCategoryId,
    ): void {
        if ($fromCategoryId === null) {
            throw new RuntimeException('Choose which allowance the money comes from.');
        }

        if ($fromCategoryId === $toCategoryId) {
            throw new RuntimeException('An allowance cannot top itself up.');
        }

        $source = collect($this->otherAllowances($plan, $toCategoryId))
            ->firstWhere('category_id', $fromCategoryId);

        if ($source === null || Money::lt($source['available'], $amount)) {
            throw new RuntimeException(
                'That allowance only has '.($source['available'] ?? '0.00').' left to give.'
            );
        }

        $plan->budgetCategories()
            ->where('category_id', $fromCategoryId)
            ->where('is_allowance', true)
            ->update(['budget_amount' => Money::sub($source['allocated'], $amount)]);
    }

    /** Savings that could still be moved: planned less what is already saved. */
    private function reducibleSavings(MonthlyPlan $plan): string
    {
        $plan->loadMissing('savingsAllocations');

        return Money::sum($plan->savingsAllocations->map(
            fn (PlanSavingsAllocation $row) => Money::floorAtZero(
                Money::sub($row->planned_amount, $row->saved_amount)
            )
        ));
    }

    /** Debt payments that could still be moved: planned less what is already paid. */
    private function reducibleDebts(MonthlyPlan $plan, ?int $exceptDebtId = null): string
    {
        $plan->loadMissing('debtAllocations');

        return Money::sum($plan->debtAllocations
            ->reject(fn (PlanDebtAllocation $row) => $row->debt_id === $exceptDebtId)
            ->map(fn (PlanDebtAllocation $row) => Money::floorAtZero(
                Money::sub($row->planned_amount, $row->paid_amount)
            )));
    }

    private function takeFromSavings(MonthlyPlan $plan, string $amount): void
    {
        $left = $amount;

        // Lowest priority first: the goal that matters least gives up first.
        $allocations = $plan->savingsAllocations()
            ->with('savingsGoal')
            ->get()
            ->sortByDesc(fn (PlanSavingsAllocation $row) => $row->savingsGoal->priority);

        foreach ($allocations as $allocation) {
            if (! Money::isPositive($left)) {
                break;
            }

            $movable = Money::floorAtZero(
                Money::sub($allocation->planned_amount, $allocation->saved_amount)
            );
            $take = Money::min($left, $movable);

            if (Money::isPositive($take)) {
                $allocation->forceFill([
                    'planned_amount' => Money::sub($allocation->planned_amount, $take),
                ])->save();

                $left = Money::sub($left, $take);
            }
        }
    }

    private function takeFromDebts(MonthlyPlan $plan, string $amount, int $exceptDebtId): void
    {
        $left = $amount;

        // Lowest interest first: the cheapest debt to leave alone for a month.
        $allocations = $plan->debtAllocations()
            ->with('debt')
            ->get()
            ->reject(fn (PlanDebtAllocation $row) => $row->debt_id === $exceptDebtId)
            ->sortBy(fn (PlanDebtAllocation $row) => (float) ($row->debt->interest_rate ?? 0));

        foreach ($allocations as $allocation) {
            if (! Money::isPositive($left)) {
                break;
            }

            $movable = Money::floorAtZero(
                Money::sub($allocation->planned_amount, $allocation->paid_amount)
            );
            $take = Money::min($left, $movable);

            if (Money::isPositive($take)) {
                $allocation->forceFill([
                    'planned_amount' => Money::sub($allocation->planned_amount, $take),
                ])->save();

                $left = Money::sub($left, $take);
            }
        }
    }

    /**
     * Weeks that have not finished yet. A week already spent cannot give
     * anything back, so the reduction lands on what is left of the cycle.
     *
     * @return list<\App\Models\WeeklyBudget>
     */
    private function remainingWeeks(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        return $plan->weeklyBudgets()
            ->orderBy('week_number')
            ->get()
            ->filter(fn ($week) => CarbonImmutable::instance($week->end_date)->gte($today))
            ->values()
            ->all();
    }

    /**
     * Spread the reduction across the weeks that are left, never below what a
     * week has already spent — that money is gone and the week has to keep
     * showing it.
     */
    private function reduceRemainingWeeks(MonthlyPlan $plan, string $amount): void
    {
        $weeks = $this->remainingWeeks($plan);

        if ($weeks === []) {
            return;
        }

        $left = $amount;

        // Two passes: an even share first, then whatever a week could not give
        // is picked up by the others.
        foreach ([true, false] as $evenShare) {
            $shares = $evenShare ? Money::split($left, count($weeks)) : null;

            foreach ($weeks as $index => $week) {
                if (! Money::isPositive($left)) {
                    return;
                }

                $spent = $this->budgets->weeklySummary($week)['spent'];
                $floor = Money::of($spent);
                $current = $week->effectiveBudget();

                $movable = Money::floorAtZero(Money::sub($current, $floor));
                $wanted = $evenShare ? Money::min($shares[$index], $left) : $left;
                $take = Money::min($wanted, $movable);

                if (Money::isPositive($take)) {
                    $week->forceFill([
                        'adjusted_amount' => Money::sub($current, $take),
                    ])->save();

                    $left = Money::sub($left, $take);
                }
            }
        }
    }
}
