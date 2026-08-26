<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'original_amount' => Money::of($this->original_amount),
            'current_balance' => Money::of($this->current_balance),
            'credit_limit' => $this->credit_limit === null ? null : Money::of($this->credit_limit),
            'interest_rate' => $this->interest_rate === null ? null : (string) $this->interest_rate,
            'minimum_payment' => Money::of($this->minimum_payment),
            'planned_payment' => Money::of($this->planned_payment),
            'due_day' => $this->due_day,
            'remaining_installments' => $this->remaining_installments,
            'installment_amount' => $this->installment_amount === null ? null : Money::of($this->installment_amount),
            // Schedule total, not a settlement quote — the UI labels it as such.
            'scheduled_remaining' => $this->scheduledRemaining(),
            'early_settlement_amount' => $this->early_settlement_amount === null
                ? null
                : Money::of($this->early_settlement_amount),
            'progress_percentage' => $this->progressPercentage(),
            'utilisation_percentage' => $this->utilisationPercentage(),
            'status' => $this->status,
            // What the active plan asked for, which is not always the debt's
            // standing planned payment: the planner can change it for a single
            // cycle, and part of it may already be paid.
            'cycle' => $this->when(
                $this->relationLoaded('planAllocations'),
                fn () => $this->cyclePayload(),
            ),
            'payments' => DebtPaymentResource::collection($this->whenLoaded('payments')),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function cyclePayload(): ?array
    {
        $allocation = $this->planAllocations->first();

        if ($allocation === null) {
            return null;
        }

        $planned = Money::of($allocation->planned_amount);
        $paid = Money::of($allocation->paid_amount);

        return [
            'planned' => $planned,
            'paid' => $paid,
            'outstanding' => Money::floorAtZero(Money::sub($planned, $paid)),
        ];
    }
}
