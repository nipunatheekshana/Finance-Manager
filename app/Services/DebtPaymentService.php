<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\PlanDebtAllocation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies money moving on and off a debt.
 *
 * Two things move a balance: payments reduce it, and spending charged to the
 * account increases it. Both are recorded, so a credit card that is being paid
 * down while still being used shows its real balance rather than the
 * flattering one.
 */
class DebtPaymentService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Record a payment against a debt and reduce the balance.
     *
     * @param  array{amount: mixed, payment_date?: mixed, interest_amount?: mixed, notes?: ?string, monthly_plan_id?: ?int, reduce_installment?: bool}  $data
     */
    public function recordPayment(Debt $debt, array $data): DebtPayment
    {
        return DB::transaction(function () use ($debt, $data) {
            $amount = Money::of($data['amount']);
            $date = CarbonImmutable::parse($data['payment_date'] ?? CarbonImmutable::today());

            $interest = isset($data['interest_amount']) && $data['interest_amount'] !== null
                ? Money::of($data['interest_amount'])
                : $this->estimateMonthlyInterest($debt);

            // Interest is serviced first; only the rest touches the principal.
            $principal = Money::floorAtZero(Money::sub($amount, $interest));

            $balanceBefore = Money::of($debt->current_balance);
            $balanceAfter = Money::floorAtZero(Money::sub($balanceBefore, $principal));

            $reduceInstallment = (bool) ($data['reduce_installment'] ?? $debt->isInstallment());

            $payment = DebtPayment::create([
                'debt_id' => $debt->id,
                'monthly_plan_id' => $data['monthly_plan_id'] ?? null,
                'amount' => $amount,
                'payment_date' => $date->toDateString(),
                'interest_amount' => $interest,
                'principal_amount' => $principal,
                'balance_after' => $balanceAfter,
                'reduced_installment' => $reduceInstallment,
                'notes' => $data['notes'] ?? null,
            ]);

            $attributes = ['current_balance' => $balanceAfter];

            if ($reduceInstallment && $debt->remaining_installments !== null) {
                $attributes['remaining_installments'] = max(0, $debt->remaining_installments - 1);
            }

            if (Money::isZero($balanceAfter) || ($attributes['remaining_installments'] ?? null) === 0) {
                $attributes['status'] = Money::isZero($balanceAfter) ? 'paid_off' : $debt->status;
            }

            $debt->forceFill($attributes)->save();

            $this->syncPlanAllocation($debt, $payment);

            $this->audit->record(
                $debt->user_id,
                'debt.payment_recorded',
                $debt,
                ['current_balance' => $balanceBefore],
                ['current_balance' => $balanceAfter, 'payment' => $amount],
            );

            return $payment;
        });
    }

    /**
     * Increase a debt balance because new spending was charged to it.
     */
    public function applyExpenseToDebt(Expense $expense, Debt $debt): void
    {
        $before = Money::of($debt->current_balance);
        $after = Money::add($before, $expense->amount);

        $debt->forceFill([
            'current_balance' => $after,
            // Spending on a cleared card makes it active again.
            'status' => $debt->status === 'paid_off' ? 'active' : $debt->status,
        ])->save();

        $this->audit->record(
            $debt->user_id,
            'debt.spending_added',
            $debt,
            ['current_balance' => $before],
            ['current_balance' => $after, 'expense_id' => $expense->id],
        );
    }

    /**
     * Reverse a charge when the linked expense is deleted or re-pointed.
     */
    public function reverseExpenseFromDebt(Expense $expense, Debt $debt, ?string $amount = null): void
    {
        $before = Money::of($debt->current_balance);
        $after = Money::floorAtZero(Money::sub($before, $amount ?? $expense->amount));

        $debt->forceFill(['current_balance' => $after])->save();

        $this->audit->record(
            $debt->user_id,
            'debt.spending_reversed',
            $debt,
            ['current_balance' => $before],
            ['current_balance' => $after, 'expense_id' => $expense->id],
        );
    }

    public function deletePayment(DebtPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $debt = $payment->debt;

            $before = Money::of($debt->current_balance);
            $restored = Money::add($before, $payment->principal_amount ?? $payment->amount);

            $attributes = ['current_balance' => $restored, 'status' => 'active'];

            if ($payment->reduced_installment && $debt->remaining_installments !== null) {
                $attributes['remaining_installments'] = $debt->remaining_installments + 1;
            }

            $debt->forceFill($attributes)->save();

            $this->audit->record(
                $debt->user_id,
                'debt.payment_deleted',
                $debt,
                ['current_balance' => $before],
                ['current_balance' => $restored],
            );

            $payment->delete();
        });
    }

    /**
     * One month of interest at the debt's configured rate, or zero when the
     * rate is unknown.
     */
    public function estimateMonthlyInterest(Debt $debt): string
    {
        if ($debt->interest_rate === null || ! Money::isPositive((string) $debt->interest_rate)) {
            return '0.00';
        }

        $monthlyRate = Money::div((string) $debt->interest_rate, '1200');

        return Money::mul($debt->current_balance, $monthlyRate);
    }

    /** Keep the plan's "paid so far" figure in step with recorded payments. */
    private function syncPlanAllocation(Debt $debt, DebtPayment $payment): void
    {
        $planId = $payment->monthly_plan_id;

        if ($planId === null) {
            $plan = MonthlyPlan::query()
                ->where('user_id', $debt->user_id)
                ->whereDate('cycle_start_date', '<=', $payment->payment_date->toDateString())
                ->whereDate('cycle_end_date', '>=', $payment->payment_date->toDateString())
                ->first();

            if ($plan === null) {
                return;
            }

            $planId = $plan->id;
            $payment->forceFill(['monthly_plan_id' => $planId])->save();
        }

        $allocation = PlanDebtAllocation::query()
            ->where('monthly_plan_id', $planId)
            ->where('debt_id', $debt->id)
            ->first();

        if ($allocation === null) {
            return;
        }

        $paid = Money::of(
            DebtPayment::query()
                ->where('debt_id', $debt->id)
                ->where('monthly_plan_id', $planId)
                ->sum('amount')
        );

        $allocation->forceFill(['paid_amount' => $paid])->save();
    }
}
