<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanDebtAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'debt_id' => $this->debt_id,
            'minimum_payment' => Money::of($this->minimum_payment),
            'recommended_payment' => Money::of($this->recommended_payment),
            'planned_amount' => Money::of($this->planned_amount),
            'paid_amount' => Money::of($this->paid_amount),
            'outstanding' => Money::floorAtZero(Money::sub($this->planned_amount, $this->paid_amount)),
            'debt' => new DebtResource($this->whenLoaded('debt')),
        ];
    }
}
