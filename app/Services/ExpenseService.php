<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\WeeklyBudget;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creating, editing and removing expenses.
 *
 * Recording an expense is the single most frequent action in the app, so this
 * keeps the work per write small: resolve which plan/week the date falls in,
 * apply any credit-card side effect, and let the budget services derive
 * everything else on read.
 */
class ExpenseService
{
    public function __construct(
        private readonly DebtPaymentService $debtPayments,
        private readonly AlertService $alerts,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Expense
    {
        return DB::transaction(function () use ($user, $data) {
            // Offline sync replays the same expense; the client uuid makes that safe.
            if (! empty($data['client_uuid'])) {
                $existing = Expense::query()
                    ->where('user_id', $user->id)
                    ->where('client_uuid', $data['client_uuid'])
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $date = CarbonImmutable::parse($data['expense_date'] ?? CarbonImmutable::today())->startOfDay();
            $paymentMethod = $user->paymentMethods()->findOrFail($data['payment_method_id']);
            $debtId = $this->resolveDebtId($user, $paymentMethod, $data);

            $expense = Expense::create([
                'user_id' => $user->id,
                'category_id' => $data['category_id'],
                'payment_method_id' => $paymentMethod->id,
                'amount' => Money::of($data['amount']),
                'expense_date' => $date->toDateString(),
                'description' => $data['description'] ?? null,
                'debt_id' => $debtId,
                'recurring_transaction_id' => $data['recurring_transaction_id'] ?? null,
                'client_uuid' => $data['client_uuid'] ?? null,
            ] + $this->planLinkage($user, $date));

            if ($debtId !== null) {
                $this->debtPayments->applyExpenseToDebt($expense, Debt::findOrFail($debtId));
            }

            $this->alerts->afterExpenseRecorded($expense);

            return $expense->load(['category', 'paymentMethod']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data) {
            $user = $expense->user;
            $originalAmount = Money::of($expense->amount);
            $originalDebtId = $expense->debt_id;

            $date = CarbonImmutable::parse($data['expense_date'] ?? $expense->expense_date)->startOfDay();

            $paymentMethod = array_key_exists('payment_method_id', $data)
                ? $user->paymentMethods()->findOrFail($data['payment_method_id'])
                : $expense->paymentMethod;

            $newDebtId = $this->resolveDebtId($user, $paymentMethod, $data + ['debt_id' => $expense->debt_id]);

            // Unwind the old card charge before applying the new one, so editing
            // an amount or switching payment method leaves the balance correct.
            if ($originalDebtId !== null) {
                $this->debtPayments->reverseExpenseFromDebt(
                    $expense,
                    Debt::findOrFail($originalDebtId),
                    $originalAmount,
                );
            }

            $expense->fill([
                'category_id' => $data['category_id'] ?? $expense->category_id,
                'payment_method_id' => $paymentMethod->id,
                'amount' => array_key_exists('amount', $data) ? Money::of($data['amount']) : $originalAmount,
                'expense_date' => $date->toDateString(),
                'description' => array_key_exists('description', $data) ? $data['description'] : $expense->description,
                'debt_id' => $newDebtId,
            ] + $this->planLinkage($user, $date));

            $this->audit->recordChanges(
                $user->id,
                'expense.updated',
                $expense,
                ['amount', 'category_id', 'payment_method_id', 'expense_date'],
            );

            $expense->save();

            if ($newDebtId !== null) {
                $this->debtPayments->applyExpenseToDebt($expense, Debt::findOrFail($newDebtId));
            }

            $this->alerts->afterExpenseRecorded($expense);

            return $expense->load(['category', 'paymentMethod']);
        });
    }

    public function delete(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            if ($expense->debt_id !== null) {
                $this->debtPayments->reverseExpenseFromDebt($expense, $expense->debt);
            }

            $this->audit->record(
                $expense->user_id,
                'expense.deleted',
                $expense,
                ['amount' => Money::of($expense->amount), 'date' => $expense->expense_date->toDateString()],
                null,
            );

            $expense->delete();
        });
    }

    /**
     * Bulk-create expenses queued while the device was offline.
     *
     * Each entry carries a client uuid, so replaying the same queue twice can
     * never create duplicates.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{synced: list<Expense>, failed: list<array{client_uuid: ?string, message: string}>}
     */
    public function syncOffline(User $user, array $entries): array
    {
        $synced = [];
        $failed = [];

        foreach ($entries as $entry) {
            try {
                $synced[] = $this->create($user, $entry);
            } catch (\Throwable $e) {
                report($e);

                $failed[] = [
                    'client_uuid' => $entry['client_uuid'] ?? null,
                    'message' => 'This expense could not be synced.',
                ];
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Which plan and week an expense date falls inside.
     *
     * @return array{monthly_plan_id: ?int, weekly_budget_id: ?int}
     */
    private function planLinkage(User $user, CarbonImmutable $date): array
    {
        $week = WeeklyBudget::query()
            ->whereHas('monthlyPlan', fn ($query) => $query->where('user_id', $user->id))
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();

        if ($week !== null) {
            return [
                'monthly_plan_id' => $week->monthly_plan_id,
                'weekly_budget_id' => $week->id,
            ];
        }

        $planId = $user->monthlyPlans()
            ->whereDate('cycle_start_date', '<=', $date->toDateString())
            ->whereDate('cycle_end_date', '>=', $date->toDateString())
            ->value('id');

        return ['monthly_plan_id' => $planId, 'weekly_budget_id' => null];
    }

    /**
     * A payment method linked to a debt charges that debt. An explicit debt_id
     * on the request wins, so a one-off card purchase can still be attributed.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDebtId(User $user, PaymentMethod $paymentMethod, array $data): ?int
    {
        if (array_key_exists('debt_id', $data) && $data['debt_id'] !== null) {
            // Never trust an id from the client without checking ownership.
            return $user->debts()->whereKey($data['debt_id'])->value('id');
        }

        return $paymentMethod->debt_id;
    }
}
