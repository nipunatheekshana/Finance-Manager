<?php

namespace App\Http\Resources;

use App\Models\FinancialProfile;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = [];
        foreach (FinancialProfile::NOTIFICATION_TYPES as $type) {
            $settings[$type] = $this->wantsNotification($type);
        }

        return [
            'id' => $this->id,
            'base_salary' => Money::of($this->base_salary),
            'salary_day' => $this->salary_day,
            'has_extra_income' => $this->has_extra_income,
            'default_buffer' => Money::of($this->default_buffer),
            'extra_debt_percentage' => $this->extra_debt_percentage,
            'extra_savings_percentage' => $this->extra_savings_percentage,
            'extra_spending_percentage' => $this->extra_spending_percentage,
            'theme' => $this->theme,
            'notification_settings' => $settings,
            'onboarding_completed' => $this->hasCompletedOnboarding(),
        ];
    }
}
