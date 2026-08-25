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
            'income_mode' => $this->income_mode->value,
            'income_mode_label' => $this->income_mode->label(),
            'cycle_anchor' => $this->cycle_anchor->value,
            'funding_method' => $this->funding_method->value,
            'funding_method_label' => $this->funding_method->label(),
            'has_salary' => $this->hasSalary(),
            'has_irregular_income' => $this->hasIrregularIncome(),
            'base_salary' => Money::of($this->base_salary),
            'target_draw' => Money::of($this->target_draw),
            'forecast_months' => $this->forecast_months,
            'forecast_factor' => $this->forecast_factor,
            'cycle_start_day' => $this->cycle_start_day,
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
