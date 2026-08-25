<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'debt_id' => $this->debt_id,
            'amount' => Money::of($this->amount),
            'payment_date' => $this->payment_date->toDateString(),
            'interest_amount' => $this->interest_amount === null ? null : Money::of($this->interest_amount),
            'principal_amount' => $this->principal_amount === null ? null : Money::of($this->principal_amount),
            'balance_after' => $this->balance_after === null ? null : Money::of($this->balance_after),
            'notes' => $this->notes,
        ];
    }
}
