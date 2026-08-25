<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomeTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => Money::of($this->amount),
            'received_date' => $this->received_date->toDateString(),
            'type' => $this->type,
            'description' => $this->description,
            'income_source_id' => $this->income_source_id,
            'monthly_plan_id' => $this->monthly_plan_id,
        ];
    }
}
