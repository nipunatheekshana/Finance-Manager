<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomeSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'cadence' => $this->cadence->value,
            'client_name' => $this->client_name,
            'expected_amount' => Money::of($this->expected_amount),
            'active' => $this->active,
            'archived' => $this->archived_at !== null,
        ];
    }
}
