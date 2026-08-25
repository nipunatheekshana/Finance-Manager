<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MonthlyPlanRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('monthlyPlan', 'update');
    }

    public function rules(): array
    {
        return [
            'expected_income' => $this->moneyRules(false),
            'actual_income' => $this->moneyRules(false),
            'buffer' => $this->moneyRules(false),
            // Opting into a deficit is the only way to finalise an
            // over-allocated plan, and it has to be a deliberate choice.
            'allow_deficit' => ['sometimes', 'boolean'],
        ];
    }
}
