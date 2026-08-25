<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'monthly_plan_id' => $this->monthly_plan_id,
            'weekly_budget_id' => $this->weekly_budget_id,
            'source_weekly_budget_id' => $this->source_weekly_budget_id,
            'category_id' => $this->category_id,
            'type' => $this->type->value,
            'amount' => Money::of($this->amount),
            'original_amount' => $this->original_amount === null ? null : Money::of($this->original_amount),
            'adjusted_amount' => $this->adjusted_amount === null ? null : Money::of($this->adjusted_amount),
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
