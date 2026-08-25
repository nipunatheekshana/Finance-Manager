<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => Money::of($this->amount),
            'minimum_amount' => $this->minimum_amount === null ? null : Money::of($this->minimum_amount),
            'maximum_amount' => $this->maximum_amount === null ? null : Money::of($this->maximum_amount),
            'amount_type' => $this->amount_type->value,
            'is_variable' => $this->minimum_amount !== null || $this->maximum_amount !== null,
            'category_id' => $this->category_id,
            'payment_method_id' => $this->payment_method_id,
            'frequency' => $this->frequency->value,
            'due_day' => $this->due_day,
            'day_of_week' => $this->day_of_week,
            'interval_days' => $this->interval_days,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'active' => $this->active,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'payment_method' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
        ];
    }
}
