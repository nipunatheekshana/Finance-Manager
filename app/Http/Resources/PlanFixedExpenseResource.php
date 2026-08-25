<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanFixedExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recurring_transaction_id' => $this->recurring_transaction_id,
            'category_id' => $this->category_id,
            'payment_method_id' => $this->payment_method_id,
            'name' => $this->name,
            'amount' => Money::of($this->amount),
            'actual_amount' => $this->actual_amount === null ? null : Money::of($this->actual_amount),
            'effective_amount' => $this->effectiveAmount(),
            // How many times this bill falls inside the cycle.
            'occurrences' => $this->occurrences,
            'due_date' => $this->due_date?->toDateString(),
            'postponed_to' => $this->postponed_to?->toDateString(),
            'status' => $this->status,
            'counts_toward_plan' => $this->countsTowardPlan(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
