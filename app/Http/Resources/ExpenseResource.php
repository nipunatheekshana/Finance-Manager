<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => Money::of($this->amount),
            'expense_date' => $this->expense_date->toDateString(),
            'description' => $this->description,
            'category_id' => $this->category_id,
            'payment_method_id' => $this->payment_method_id,
            'debt_id' => $this->debt_id,
            'recurring_transaction_id' => $this->recurring_transaction_id,
            'client_uuid' => $this->client_uuid,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'payment_method' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
