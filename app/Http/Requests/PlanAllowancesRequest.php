<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanAllowancesRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('monthlyPlan', 'update');
    }

    public function rules(): array
    {
        return [
            'allowances' => ['present', 'array', 'max:40'],
            'allowances.*.category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->id),
            ],
            // Zero removes the allowance for this cycle and returns the money
            // to day-to-day spending.
            'allowances.*.amount' => $this->moneyRules(),
        ];
    }
}
