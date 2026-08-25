<?php

namespace App\Services;

use App\Enums\SurplusAction;
use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What happens to the money a finished cycle did not use.
 *
 * Leftover is real: it is still sitting in the user's bank account. Left
 * untracked it quietly disappears from the plan while the balance keeps
 * growing, so at the end of every cycle the user is asked what it should do.
 *
 * Nothing is moved automatically. The options are presented with their exact
 * effect and only the chosen one is applied, the same rule the overspend flow
 * follows.
 */
class CycleSurplusService
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly DebtPaymentService $debtPayments,
        private readonly SavingsService $savings,
        private readonly AuditService $audit,
    ) {}

    /**
     * What a finished cycle left over.
     *
     * @return array<string, mixed>
     */
    public function summarise(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $spent = $this->budgets->spentBetween($plan->user_id, $plan->cycle_start_date, $plan->cycle_end_date);
        $unspent = Money::floorAtZero(Money::sub($plan->spending_budget, $spent));
        $unusedBuffer = $plan->bufferRemaining();
        $total = Money::add($unspent, $unusedBuffer);

        $cycleEnded = CarbonImmutable::instance($plan->cycle_end_date)->startOfDay()->lt($today);
        $resolved = $plan->surplus_resolved_at !== null;

        return [
            'plan_id' => $plan->id,
            'plan_label' => $plan->label(),
            'cycle_end' => $plan->cycle_end_date->toDateString(),
            'spending_budget' => Money::of($plan->spending_budget),
            'spent' => $spent,
            'unspent_budget' => $unspent,
            'buffer' => Money::of($plan->buffer),
            'buffer_used' => Money::of($plan->buffer_used),
            'unused_buffer' => $unusedBuffer,
            'total' => $total,
            'has_surplus' => Money::isPositive($total),
            'cycle_ended' => $cycleEnded,
            // Only a finished, unresolved cycle with money left needs a decision.
            'needs_decision' => $cycleEnded && ! $resolved && Money::isPositive($total),
            'resolved' => $resolved,
            'resolved_at' => $plan->surplus_resolved_at?->toIso8601String(),
            'resolved_amount' => $plan->surplus_amount === null ? null : Money::of($plan->surplus_amount),
            'carried_forward' => Money::of($plan->carried_forward),
        ];
    }

    /**
     * The choices, each with the before/after figures so the user can see
     * exactly what a button will do before pressing it.
     *
     * @return array<string, mixed>
     */
    public function optionsFor(MonthlyPlan $plan, ?CarbonImmutable $today = null): array
    {
        $summary = $this->summarise($plan, $today);
        $total = $summary['total'];

        $debts = $plan->user->debts()->active()
            ->orderByDesc('interest_rate')
            ->orderByDesc('current_balance')
            ->get()
            ->map(fn (Debt $debt) => [
                'debt_id' => $debt->id,
                'name' => $debt->name,
                'type' => $debt->type->value,
                'balance' => Money::of($debt->current_balance),
                // Never offer to pay more than is actually owed.
                'applicable' => Money::min($total, $debt->current_balance),
                'resulting_balance' => Money::floorAtZero(
                    Money::sub($debt->current_balance, Money::min($total, $debt->current_balance))
                ),
            ])
            ->values()
            ->all();

        $goals = $plan->user->savingsGoals()->active()
            ->orderBy('priority')
            ->get()
            ->map(fn (SavingsGoal $goal) => [
                'savings_goal_id' => $goal->id,
                'name' => $goal->name,
                'current_amount' => Money::of($goal->current_amount),
                'target_amount' => Money::of($goal->target_amount),
                'applicable' => $total,
                'resulting_amount' => Money::add($goal->current_amount, $total),
            ])
            ->values()
            ->all();

        $nextPlan = $this->nextPlan($plan);

        return $summary + [
            'debts' => $debts,
            'savings_goals' => $goals,
            'carry_forward' => [
                'next_label' => $this->nextPeriodLabel($plan),
                'current_opening_balance' => $nextPlan === null
                    ? '0.00'
                    : Money::of($nextPlan->opening_balance),
                'resulting_opening_balance' => $nextPlan === null
                    ? $total
                    : Money::add($nextPlan->opening_balance, $total),
            ],
        ];
    }

    /**
     * Apply the allocations the user chose.
     *
     * An empty list is a valid choice — "leave it in the bank" — and simply
     * marks the cycle resolved so the prompt stops asking.
     *
     * @param  list<array{type: string, amount: mixed, debt_id?: int, savings_goal_id?: int}>  $allocations
     * @return array<string, mixed>
     */
    public function apply(MonthlyPlan $plan, array $allocations, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $summary = $this->summarise($plan, $today);

        if (! $summary['cycle_ended']) {
            throw new InvalidArgumentException(
                'This cycle has not finished yet, so there is nothing to settle.'
            );
        }

        if ($plan->surplus_resolved_at !== null) {
            throw new InvalidArgumentException('This cycle\'s leftover has already been settled.');
        }

        $available = Money::of($summary['total']);
        $requested = Money::sum(array_map(fn (array $row) => $row['amount'] ?? '0', $allocations));

        if (Money::gt($requested, $available)) {
            throw new InvalidArgumentException(
                'You can allocate at most LKR '.number_format((float) $available, 2).', which is what the cycle left over.'
            );
        }

        return DB::transaction(function () use ($plan, $allocations, $summary, $available, $requested, $today) {
            $applied = [];

            foreach ($allocations as $row) {
                $amount = Money::of($row['amount'] ?? '0');

                if (! Money::isPositive($amount)) {
                    continue;
                }

                $applied[] = match (SurplusAction::from($row['type'])) {
                    SurplusAction::Debt => $this->payDebt($plan, (int) $row['debt_id'], $amount, $today),
                    SurplusAction::Savings => $this->addToSavings($plan, (int) $row['savings_goal_id'], $amount, $today),
                    SurplusAction::CarryForward => $this->carryForward($plan, $amount),
                    SurplusAction::LeaveInBank => [
                        'type' => SurplusAction::LeaveInBank->value,
                        'amount' => $amount,
                        'label' => 'Left in your bank account',
                    ],
                };
            }

            // Buffer that was swept away is buffer that has been used, so the
            // plan's own figures stay consistent if it is ever reopened.
            $this->consumeBuffer($plan, $requested, $summary);

            $plan->forceFill([
                'surplus_amount' => $available,
                'surplus_resolved_at' => now(),
            ])->save();

            $this->audit->record(
                $plan->user_id,
                'plan.surplus_resolved',
                $plan,
                ['surplus' => $available],
                ['allocated' => $requested, 'allocations' => count($applied)],
            );

            return [
                'summary' => $this->summarise($plan->fresh(), $today),
                'applied' => $applied,
                'allocated' => $requested,
                'left_in_bank' => Money::sub($available, $requested),
            ];
        });
    }

    /**
     * The most recent finished cycle still waiting on a decision.
     */
    public function pendingFor(User $user, ?CarbonImmutable $today = null): ?array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $plan = MonthlyPlan::query()
            ->where('user_id', $user->id)
            ->whereNotNull('finalized_at')
            ->whereNull('surplus_resolved_at')
            ->whereDate('cycle_end_date', '<', $today->toDateString())
            ->orderByDesc('cycle_end_date')
            ->first();

        if ($plan === null) {
            return null;
        }

        $summary = $this->summarise($plan, $today);

        return $summary['needs_decision'] ? $summary : null;
    }

    /**
     * What the next cycle should open with, based on what this one handed on.
     */
    public function openingBalanceFor(User $user, int $year, int $month): string
    {
        $previous = $this->previousPlan($user, $year, $month);

        return $previous === null ? '0.00' : Money::of($previous->carried_forward);
    }

    /** @return array<string, mixed> */
    private function payDebt(MonthlyPlan $plan, int $debtId, string $amount, CarbonImmutable $today): array
    {
        $debt = $plan->user->debts()->findOrFail($debtId);
        $before = Money::of($debt->current_balance);

        $this->debtPayments->recordPayment($debt, [
            'amount' => $amount,
            'payment_date' => $today->toDateString(),
            // Not a scheduled installment — an extra payment out of leftover.
            'reduce_installment' => false,
            'notes' => 'Leftover from '.$plan->label(),
        ]);

        return [
            'type' => SurplusAction::Debt->value,
            'amount' => $amount,
            'label' => 'Paid off '.$debt->name,
            'debt_id' => $debt->id,
            'balance_before' => $before,
            'balance_after' => Money::of($debt->fresh()->current_balance),
        ];
    }

    /** @return array<string, mixed> */
    private function addToSavings(MonthlyPlan $plan, int $goalId, string $amount, CarbonImmutable $today): array
    {
        $goal = $plan->user->savingsGoals()->findOrFail($goalId);
        $before = Money::of($goal->current_amount);

        $this->savings->deposit($goal, [
            'amount' => $amount,
            'transaction_date' => $today->toDateString(),
            'description' => 'Leftover from '.$plan->label(),
        ]);

        return [
            'type' => SurplusAction::Savings->value,
            'amount' => $amount,
            'label' => 'Added to '.$goal->name,
            'savings_goal_id' => $goal->id,
            'balance_before' => $before,
            'balance_after' => Money::of($goal->fresh()->current_amount),
        ];
    }

    /** @return array<string, mixed> */
    private function carryForward(MonthlyPlan $plan, string $amount): array
    {
        $plan->forceFill([
            'carried_forward' => Money::add($plan->carried_forward, $amount),
        ])->save();

        // If next cycle's plan already exists, top it up now; otherwise it picks
        // the figure up from this plan when it is drafted.
        $next = $this->nextPlan($plan);

        if ($next !== null) {
            $next->forceFill([
                'opening_balance' => Money::add($next->opening_balance, $amount),
            ])->save();
        }

        return [
            'type' => SurplusAction::CarryForward->value,
            'amount' => $amount,
            'label' => 'Added to '.$this->nextPeriodLabel($plan).' spending',
        ];
    }

    /**
     * Draw the allocated total from unspent budget first, then the buffer.
     */
    private function consumeBuffer(MonthlyPlan $plan, string $allocated, array $summary): void
    {
        $fromBuffer = Money::floorAtZero(Money::sub($allocated, $summary['unspent_budget']));

        if (! Money::isPositive($fromBuffer)) {
            return;
        }

        $plan->forceFill([
            'buffer_used' => Money::add($plan->buffer_used, $fromBuffer),
        ])->save();
    }

    private function nextPlan(MonthlyPlan $plan): ?MonthlyPlan
    {
        [$year, $month] = $this->nextPeriod($plan);

        return MonthlyPlan::query()
            ->where('user_id', $plan->user_id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();
    }

    private function previousPlan(User $user, int $year, int $month): ?MonthlyPlan
    {
        $previous = CarbonImmutable::create($year, $month, 1)->subMonthNoOverflow();

        return MonthlyPlan::query()
            ->where('user_id', $user->id)
            ->where('year', $previous->year)
            ->where('month', $previous->month)
            ->first();
    }

    /** @return array{0: int, 1: int} */
    private function nextPeriod(MonthlyPlan $plan): array
    {
        $next = CarbonImmutable::create($plan->year, $plan->month, 1)->addMonthNoOverflow();

        return [$next->year, $next->month];
    }

    private function nextPeriodLabel(MonthlyPlan $plan): string
    {
        [$year, $month] = $this->nextPeriod($plan);

        return CarbonImmutable::create($year, $month, 1)->format('F Y');
    }
}
