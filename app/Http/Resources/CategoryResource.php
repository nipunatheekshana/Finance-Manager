<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'monthly_budget' => $this->monthly_budget === null ? null : Money::of($this->monthly_budget),
            'weekly_budget' => $this->weekly_budget === null ? null : Money::of($this->weekly_budget),
            'warning_percentage' => $this->warning_percentage,
            'is_default' => $this->is_default,
            'active' => $this->active,
            'sort_order' => $this->sort_order,
        ];
    }
}
