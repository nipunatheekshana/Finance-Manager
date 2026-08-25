<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'icon' => $this->icon,
            'debt_id' => $this->debt_id,
            // Signals to the UI that spending here will raise a debt balance.
            'increases_debt' => $this->debt_id !== null,
            'is_default' => $this->is_default,
            'active' => $this->active,
            'sort_order' => $this->sort_order,
        ];
    }
}
