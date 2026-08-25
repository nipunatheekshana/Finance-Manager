<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WeeklyBudgetsRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('monthlyPlan', 'update');
    }

    public function rules(): array
    {
        return [
            'weeks' => ['required', 'array', 'min:1', 'max:6'],
            'weeks.*.week_number' => ['required', 'integer', 'min:1', 'max:6'],
            'weeks.*.budget_amount' => $this->moneyRules(),
        ];
    }
}
