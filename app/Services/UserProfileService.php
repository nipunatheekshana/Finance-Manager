<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The account's own record of itself: what it has earned, spent, paid off and
 * put aside, cycle by cycle, plus the trail of everything it has done.
 *
 * Nothing here is new arithmetic — it is the same figures the rest of the app
 * derives, gathered into one history rather than one cycle.
 */
class UserProfileService
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly SavingsService $savings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        return [
            'lifetime' => $this->lifetime($user),
            'months' => $this->months($user),
            'debts' => $this->debtHistory($user),
            'savings' => $this->savingsHistory($user),
        ];
    }

    /**
     * The headline totals: what this account has actually achieved.
     *
     * @return array<string, mixed>
     */
    public function lifetime(User $user): array
    {
        $plans = $user->monthlyPlans()->whereIn('status', ['active', 'completed'])->get();

        $paid = Money::of(
            DebtPayment::query()
                ->whereHas('debt', fn ($q) => $q->where('user_id', $user->id))
                ->sum('amount')
        );

        $deposits = Money::of(
            SavingsTransaction::query()
                ->whereHas('savingsGoal', fn ($q) => $q->where('user_id', $user->id))
                ->whereIn('type', ['deposit', 'transfer_in'])
                ->sum('amount')
        );

        $withdrawals = Money::of(
            SavingsTransaction::query()
                ->whereHas('savingsGoal', fn ($q) => $q->where('user_id', $user->id))
                ->whereIn('type', ['withdrawal', 'transfer_out'])
                ->sum('amount')
        );

        $first = $user->expenses()->min('expense_date');

        return [
            'cycles_planned' => $plans->count(),
            'cycles_completed' => $plans->where('status', 'completed')->count(),
            'expenses_logged' => $user->expenses()->count(),
            'total_spent' => Money::of($user->expenses()->sum('amount')),
            'total_income' => Money::sum($plans->map(fn (MonthlyPlan $plan) => $plan->totalIncome())),
            'debt_paid' => $paid,
            'debts_cleared' => $user->debts()->where('status', 'paid_off')->count(),
            'saved_net' => Money::floorAtZero(Money::sub($deposits, $withdrawals)),
            'currently_saved' => $this->savings->totalSaved($user->id),
            'tracking_since' => $first === null ? null : CarbonImmutable::parse($first)->toDateString(),
        ];
    }

    /**
     * Every cycle, newest first, with how it actually went.
     *
     * @return list<array<string, mixed>>
     */
    public function months(User $user): array
    {
        return $user->monthlyPlans()
            ->whereIn('status', ['active', 'completed'])
            ->with(['debtAllocations', 'savingsAllocations'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(24)
            ->get()
            ->map(function (MonthlyPlan $plan) {
                $start = CarbonImmutable::instance($plan->cycle_start_date);
                $end = CarbonImmutable::instance($plan->cycle_end_date);

                $budget = Money::of($plan->spending_budget);
                $spent = $this->budgets->discretionarySpentBetween($plan, $start, $end);

                return [
                    'plan_id' => $plan->id,
                    'label' => $plan->label(),
                    'status' => $plan->status->value,
                    'cycle_start' => $start->toDateString(),
                    'cycle_end' => $end->toDateString(),
                    'income' => $plan->totalIncome(),
                    'spending_budget' => $budget,
                    'spent' => $spent,
                    'remaining' => Money::sub($budget, $spent),
                    'percentage_used' => Money::percentage($spent, $budget),
                    'status_label' => $this->budgets->statusFor($spent, $budget)->value,
                    'debt_paid' => Money::of($plan->debtAllocations->sum('paid_amount')),
                    'saved' => Money::of($plan->savingsAllocations->sum('saved_amount')),
                    'total_spent' => Money::of(
                        Expense::query()
                            ->where('user_id', $plan->user_id)
                            ->between($start->toDateString(), $end->toDateString())
                            ->sum('amount')
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * How each debt has come down, and which are gone.
     *
     * @return array<string, mixed>
     */
    public function debtHistory(User $user): array
    {
        $debts = $user->debts()
            ->withSum('payments as paid_total', 'amount')
            ->withCount('payments as payments_count')
            ->orderByRaw("CASE WHEN status = 'paid_off' THEN 1 ELSE 0 END")
            ->orderByDesc('current_balance')
            ->get()
            ->map(fn (Debt $debt) => [
                'debt_id' => $debt->id,
                'name' => $debt->name,
                'type_label' => $debt->type->label(),
                'status' => $debt->status,
                'original_amount' => Money::of($debt->original_amount),
                'current_balance' => Money::of($debt->current_balance),
                'paid_total' => Money::of($debt->paid_total ?? 0),
                'payments_count' => (int) $debt->payments_count,
                'progress_percentage' => $debt->progressPercentage(),
                'cleared_on' => $debt->status === 'paid_off'
                    ? $debt->payments()->max('payment_date')
                    : null,
            ])
            ->values()
            ->all();

        return [
            'items' => $debts,
            'recent_payments' => DebtPayment::query()
                ->whereHas('debt', fn ($q) => $q->where('user_id', $user->id))
                ->with('debt:id,name')
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (DebtPayment $payment) => [
                    'id' => $payment->id,
                    'debt_name' => $payment->debt?->name ?? 'Debt',
                    'amount' => Money::of($payment->amount),
                    'payment_date' => $payment->payment_date->toDateString(),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function savingsHistory(User $user): array
    {
        return [
            'goals' => $user->savingsGoals()
                ->orderByDesc('current_amount')
                ->get()
                ->map(fn ($goal) => [
                    'savings_goal_id' => $goal->id,
                    'name' => $goal->name,
                    'target_amount' => Money::of($goal->target_amount),
                    'current_amount' => Money::of($goal->current_amount),
                    'percentage' => Money::percentage($goal->current_amount, $goal->target_amount),
                    'status' => $goal->status,
                ])
                ->all(),
        ];
    }

    /**
     * Everything the account has done, newest first.
     *
     * @return array<string, mixed>
     */
    public function activity(User $user, int $perPage = 30): array
    {
        $logs = AuditLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return [
            'items' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'label' => $this->describe($log->action),
                'subject' => class_basename($log->auditable_type),
                'note' => $log->note,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'happened_at' => $log->created_at?->toIso8601String(),
            ])->all(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'total' => $logs->total(),
        ];
    }

    /** Turn an action key into something a person would say. */
    private function describe(string $action): string
    {
        return match ($action) {
            'plan.finalized' => 'Finalised a monthly plan',
            'plan.reopened' => 'Reopened a plan',
            'plan.updated' => 'Updated a plan',
            'plan.debt_added' => 'Added a debt to a running plan',
            'plan.fixed_expense_updated' => 'Changed a bill',
            'debt.payment_recorded' => 'Recorded a debt payment',
            'budget.week_adjusted' => 'Adjusted a weekly budget',
            'expense.deleted' => 'Deleted an expense',
            'savings.deposit' => 'Added to savings',
            'savings.withdrawal' => 'Took money out of savings',
            default => Str::of($action)->replace(['.', '_'], ' ')->ucfirst()->value(),
        };
    }

    /**
     * Replace the account's picture, removing whatever it had before.
     */
    public function setAvatar(User $user, \Illuminate\Http\UploadedFile $file): string
    {
        $previous = $user->avatar_path;

        $path = $file->store('avatars', 'public');

        $user->forceFill(['avatar_path' => $path])->save();

        // Only once the new one is safely stored.
        if ($previous !== null && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return $path;
    }

    public function removeAvatar(User $user): void
    {
        if ($user->avatar_path === null) {
            return;
        }

        Storage::disk('public')->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();
    }
}
