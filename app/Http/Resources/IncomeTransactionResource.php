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
            'received_date' => $this->received_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status->value,
            'is_received' => $this->status->isReceived(),
            'is_overdue' => ! $this->status->isReceived()
                && $this->due_date !== null
                && $this->due_date->isPast(),
            'type' => $this->type,
            'description' => $this->description,
            'reference' => $this->reference,
            'source' => $this->whenLoaded('incomeSource', fn () => [
                'id' => $this->incomeSource?->id,
                'name' => $this->incomeSource?->name,
                'kind' => $this->incomeSource?->kind->value,
            ]),
            'income_source_id' => $this->income_source_id,
            'monthly_plan_id' => $this->monthly_plan_id,
        ];
    }
}
