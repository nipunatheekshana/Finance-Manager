<?php

namespace App\Services;

use App\Enums\SavingsTransactionType;
use App\Models\MonthlyPlan;
use App\Models\PlanSavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deposits, withdrawals and transfers between savings goals.
 *
 * A goal's current_amount is only ever moved through this service, so the
 * transaction history always reconciles with the balance.
 */
class SavingsService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{amount: mixed, transaction_date?: mixed, description?: ?string, monthly_plan_id?: ?int}  $data
     */
    public function deposit(SavingsGoal $goal, array $data): SavingsTransaction
    {
        return $this->move($goal, SavingsTransactionType::Deposit, $data);
    }

    /**
     * @param  array{amount: mixed, transaction_date?: mixed, description?: ?string, monthly_plan_id?: ?int}  $data
     */
    public function withdraw(SavingsGoal $goal, array $data): SavingsTransaction
    {
        $amount = Money::of($data['amount']);

        if (Money::gt($amount, $goal->current_amount)) {
            throw new InvalidArgumentException(
                'You cannot withdraw more than this goal currently holds.'
            );
        }

        return $this->move($goal, SavingsTransactionType::Withdrawal, $data);
    }

    /**
     * Move money between two goals as a matched pair of transactions.
     *
     * @param  array{amount: mixed, transaction_date?: mixed, description?: ?string}  $data
     * @return array{out: SavingsTransaction, in: SavingsTransaction}
     */
    public function transfer(SavingsGoal $from, SavingsGoal $to, array $data): array
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Choose two different goals to transfer between.');
        }

        if ($from->user_id !== $to->user_id) {
            throw new InvalidArgumentException('Both goals must belong to the same account.');
        }

        $amount = Money::of($data['amount']);

        if (Money::gt($amount, $from->current_amount)) {
            throw new InvalidArgumentException(
                'You cannot transfer more than the source goal currently holds.'
            );
        }

        return DB::transaction(function () use ($from, $to, $data, $amount) {
            $date = CarbonImmutable::parse($data['transaction_date'] ?? CarbonImmutable::today());
            $description = $data['description'] ?? null;

            $out = $this->write($from, SavingsTransactionType::TransferOut, $amount, $date, $description, null, $to->id);
            $in = $this->write($to, SavingsTransactionType::TransferIn, $amount, $date, $description, null, $from->id);

            $this->audit->record(
                $from->user_id,
                'savings.transferred',
                $from,
                ['goal' => $from->name],
                ['to_goal' => $to->name, 'amount' => $amount],
            );

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * @param  array{amount: mixed, transaction_date?: mixed, description?: ?string, monthly_plan_id?: ?int}  $data
     */
    private function move(SavingsGoal $goal, SavingsTransactionType $type, array $data): SavingsTransaction
    {
        return DB::transaction(function () use ($goal, $type, $data) {
            $amount = Money::of($data['amount']);

            if (! Money::isPositive($amount)) {
                throw new InvalidArgumentException('Enter an amount greater than zero.');
            }

            $date = CarbonImmutable::parse($data['transaction_date'] ?? CarbonImmutable::today());

            $transaction = $this->write(
                $goal,
                $type,
                $amount,
                $date,
                $data['description'] ?? null,
                $data['monthly_plan_id'] ?? null,
            );

            $this->syncPlanAllocation($goal, $transaction);

            return $transaction;
        });
    }

    private function write(
        SavingsGoal $goal,
        SavingsTransactionType $type,
        string $amount,
        CarbonImmutable $date,
        ?string $description,
        ?int $planId = null,
        ?int $relatedGoalId = null,
    ): SavingsTransaction {
        $transaction = SavingsTransaction::create([
            'user_id' => $goal->user_id,
            'savings_goal_id' => $goal->id,
            'monthly_plan_id' => $planId ?? $this->planIdFor($goal, $date),
            'type' => $type->value,
            'amount' => $amount,
            'transaction_date' => $date->toDateString(),
            'description' => $description,
            'related_goal_id' => $relatedGoalId,
        ]);

        $balance = $type->increasesBalance()
            ? Money::add($goal->current_amount, $amount)
            : Money::floorAtZero(Money::sub($goal->current_amount, $amount));

        $attributes = ['current_amount' => $balance];

        if (Money::gte($balance, $goal->target_amount) && $goal->status === 'active') {
            $attributes['status'] = 'reached';
        } elseif ($goal->status === 'reached' && Money::lt($balance, $goal->target_amount)) {
            $attributes['status'] = 'active';
        }

        $goal->forceFill($attributes)->save();

        return $transaction;
    }

    public function deleteTransaction(SavingsTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $goal = $transaction->savingsGoal;

            // Undo the balance effect, then remove the record.
            $balance = $transaction->type->increasesBalance()
                ? Money::floorAtZero(Money::sub($goal->current_amount, $transaction->amount))
                : Money::add($goal->current_amount, $transaction->amount);

            $goal->forceFill([
                'current_amount' => $balance,
                'status' => Money::gte($balance, $goal->target_amount) ? 'reached' : 'active',
            ])->save();

            $transaction->delete();
        });
    }

    /**
     * Total contributed to all goals inside a window (deposits and transfers in,
     * less withdrawals and transfers out).
     */
    public function netSavedBetween(int $userId, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $rows = SavingsTransaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $net = '0.00';

        foreach ($rows as $type => $total) {
            $enum = SavingsTransactionType::from($type);
            $net = $enum->increasesBalance()
                ? Money::add($net, $total)
                : Money::sub($net, $total);
        }

        return $net;
    }

    /** Total held across every goal. */
    public function totalSaved(int $userId): string
    {
        return Money::of(SavingsGoal::query()->where('user_id', $userId)->sum('current_amount'));
    }

    private function planIdFor(SavingsGoal $goal, CarbonImmutable $date): ?int
    {
        return MonthlyPlan::query()
            ->where('user_id', $goal->user_id)
            ->whereDate('cycle_start_date', '<=', $date->toDateString())
            ->whereDate('cycle_end_date', '>=', $date->toDateString())
            ->value('id');
    }

    private function syncPlanAllocation(SavingsGoal $goal, SavingsTransaction $transaction): void
    {
        if ($transaction->monthly_plan_id === null) {
            return;
        }

        $allocation = PlanSavingsAllocation::query()
            ->where('monthly_plan_id', $transaction->monthly_plan_id)
            ->where('savings_goal_id', $goal->id)
            ->first();

        if ($allocation === null) {
            return;
        }

        $movements = SavingsTransaction::query()
            ->where('savings_goal_id', $goal->id)
            ->where('monthly_plan_id', $transaction->monthly_plan_id)
            ->get();

        $in = Money::sum($movements
            ->whereIn('type', [SavingsTransactionType::Deposit, SavingsTransactionType::TransferIn])
            ->pluck('amount'));

        // Counting deposits alone said 20,000 was put aside when 8,000 had
        // been taken straight back out. What the cycle actually saved is the
        // net, and every "still to save" figure is derived from it.
        $out = Money::sum($movements
            ->whereIn('type', [SavingsTransactionType::Withdrawal, SavingsTransactionType::TransferOut])
            ->pluck('amount'));

        $allocation->forceFill([
            'saved_amount' => Money::floorAtZero(Money::sub($in, $out)),
        ])->save();
    }
}
