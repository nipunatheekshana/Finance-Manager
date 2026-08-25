<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanAllocationsRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('monthlyPlan', 'update');
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'debts' => ['sometimes', 'array'],
            'debts.*.debt_id' => ['required', Rule::exists('debts', 'id')->where('user_id', $userId)],
            'debts.*.planned_amount' => $this->moneyRules(),

            'savings' => ['sometimes', 'array'],
            'savings.*.savings_goal_id' => ['required', Rule::exists('savings_goals', 'id')->where('user_id', $userId)],
            'savings.*.planned_amount' => $this->moneyRules(),
        ];
    }
}
