<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'message' => $this->message,
            'action_label' => $this->action_label,
            'action_route' => $this->action_route,
            'data' => $this->data,
            'triggered_on' => $this->triggered_on->toDateString(),
            'is_read' => $this->read_at !== null,
        ];
    }
}
