<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'handle' => $this->handle,
            'avatar_url' => $this->avatarUrl(),
            'initials' => $this->initials(),
            'member_since' => $this->created_at?->toDateString(),
            'profile' => new FinancialProfileResource($this->whenLoaded('financialProfile')),
        ];
    }
}
