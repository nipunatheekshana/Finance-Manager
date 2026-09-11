<?php

namespace App\Services;

use App\Enums\DebtType;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Support\Money;

/**
 * Cards as spending instruments.
 *
 * A card is still a debt underneath — the same payments, plan allocation and
 * payoff maths — but what a card *does* is different from what a loan does: it
 * has a fixed limit the bank set, purchases fill it, and payments empty it.
 * This is that view. Paying the card is the Debts screen's job.
 */
class CreditCardService
{
    public function __construct(
        private readonly DebtPayoffService $payoff,
        private readonly FinancialPlanService $plans,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $plan = $this->plans->activePlanFor($user);

        $cards = $user->debts()
            ->where('type', DebtType::CreditCard->value)
            ->orderByDesc('current_balance')
            ->get()
            ->map(fn (Debt $card) => $this->describe($card, $plan))
            ->values()
            ->all();

        $withLimit = array_filter($cards, fn (array $card) => $card['credit_limit'] !== null);

        return [
            'cards' => $cards,
            'totals' => [
                'count' => count($cards),
                'balance' => Money::sum(array_column($cards, 'balance')),
                'credit_limit' => Money::sum(array_column($withLimit, 'credit_limit')),
                'available' => Money::sum(array_column($withLimit, 'available')),
                'charged_this_cycle' => Money::sum(array_column($cards, 'charged_this_cycle')),
                'planned_payment' => Money::sum(array_column($cards, 'planned_payment')),
            ],
            'plan_label' => $plan?->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Debt $card, ?MonthlyPlan $plan): array
    {
        $charged = $plan === null ? '0.00' : $this->chargedBetween($card, $plan->cycle_start_date->toDateString(), $plan->cycle_end_date->toDateString());
        $planned = Money::of($card->planned_payment);
        $net = Money::sub($charged, $planned);

        return [
            'id' => $card->id,
            'name' => $card->name,
            'status' => $card->status,
            'credit_limit' => $card->credit_limit === null ? null : Money::of($card->credit_limit),
            'balance' => Money::of($card->current_balance),
            // Derived, so balance + available is the limit by construction.
            'available' => $card->availableCredit(),
            'utilisation_percentage' => $card->utilisationPercentage(),
            'minimum_payment' => Money::of($card->minimum_payment),
            'planned_payment' => $planned,
            'interest_rate' => $card->interest_rate === null ? null : (string) $card->interest_rate,
            'due_day' => $card->due_day,
            'charged_this_cycle' => $charged,
            'net_change' => $net,
            'is_growing' => Money::isPositive($net),
            'payoff' => $this->payoff->project($card),
            'recent_purchases' => $card->expenses()
                ->with('category:id,name,icon,color')
                ->orderByDesc('expense_date')
                ->orderByDesc('id')
                ->limit(8)
                ->get()
                ->map(fn (Expense $expense) => [
                    'id' => $expense->id,
                    'amount' => Money::of($expense->amount),
                    'expense_date' => $expense->expense_date->toDateString(),
                    'description' => $expense->description,
                    'category' => $expense->category?->name ?? 'Uncategorised',
                    'icon' => $expense->category?->icon ?? 'circle',
                    'color' => $expense->category?->color ?? 'slate',
                ])
                ->all(),
            'charges_by_cycle' => $this->chargesByCycle($card),
        ];
    }

    /**
     * What went on the card in each of the last six cycles, oldest first, for
     * the chart.
     *
     * @return list<array{label: string, charged: string}>
     */
    private function chargesByCycle(Debt $card): array
    {
        return MonthlyPlan::query()
            ->where('user_id', $card->user_id)
            ->whereIn('status', ['active', 'completed'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(fn (MonthlyPlan $plan) => [
                'label' => $plan->label(),
                'charged' => $this->chargedBetween($card, $plan->cycle_start_date->toDateString(), $plan->cycle_end_date->toDateString()),
            ])
            ->values()
            ->all();
    }

    private function chargedBetween(Debt $card, string $start, string $end): string
    {
        return Money::of($card->expenses()->between($start, $end)->sum('amount'));
    }
}
