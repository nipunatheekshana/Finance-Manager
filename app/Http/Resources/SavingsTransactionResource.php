<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'savings_goal_id' => $this->savings_goal_id,
            'type' => $this->type->value,
            'amount' => Money::of($this->amount),
            'transaction_date' => $this->transaction_date->toDateString(),
            'description' => $this->description,
            'related_goal_id' => $this->related_goal_id,
            'increases_balance' => $this->type->increasesBalance(),
        ];
    }
}
