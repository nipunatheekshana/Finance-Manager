<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordIncomeRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('monthlyPlan', 'update');
    }

    public function rules(): array
    {
        return [
            'actual_income' => $this->positiveMoneyRules(),
            // Whether to auto-split any surplus using the configured rule.
            'apply_extra_split' => ['sometimes', 'boolean'],
            'received_date' => ['sometimes', 'date'],
        ];
    }
}
