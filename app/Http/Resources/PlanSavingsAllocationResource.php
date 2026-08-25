<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanSavingsAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'savings_goal_id' => $this->savings_goal_id,
            'recommended_amount' => Money::of($this->recommended_amount),
            'planned_amount' => Money::of($this->planned_amount),
            'saved_amount' => Money::of($this->saved_amount),
            'outstanding' => Money::floorAtZero(Money::sub($this->planned_amount, $this->saved_amount)),
            'savings_goal' => new SavingsGoalResource($this->whenLoaded('savingsGoal')),
        ];
    }
}
