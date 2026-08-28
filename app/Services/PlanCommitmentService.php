<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\PlanDebtAllocation;
use App\Models\PlanSavingsAllocation;
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
    public const SOURCES = ['spending', 'buffer', 'savings', 'debts'];

    public function __construct(
        private readonly FinancialPlanService $plans,
        private readonly BudgetCalculationService $budgets,
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
