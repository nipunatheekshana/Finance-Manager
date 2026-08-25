<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsGoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'target_amount' => Money::of($this->target_amount),
            'current_amount' => Money::of($this->current_amount),
            'remaining_amount' => $this->remainingAmount(),
            'monthly_target' => Money::of($this->monthly_target),
            'allocation_type' => $this->allocation_type->value,
            'allocation_value' => Money::of($this->allocation_value),
            'target_date' => $this->target_date?->toDateString(),
            'priority' => $this->priority,
            'status' => $this->status,
            'percentage' => $this->progressPercentage(),
            'is_reached' => $this->isReached(),
            'transactions' => SavingsTransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
