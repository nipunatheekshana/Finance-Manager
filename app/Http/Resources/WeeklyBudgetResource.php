<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeeklyBudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'monthly_plan_id' => $this->monthly_plan_id,
            'week_number' => $this->week_number,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'budget_amount' => Money::of($this->budget_amount),
            'adjusted_amount' => $this->adjusted_amount === null ? null : Money::of($this->adjusted_amount),
            'effective_budget' => $this->effectiveBudget(),
            'spent_amount' => Money::of($this->spent_amount),
        ];
    }
}
