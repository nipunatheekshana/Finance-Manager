<?php

namespace App\Http\Requests;

use App\Enums\AllocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavingsGoalRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('savingsGoal', 'update');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'icon' => ['sometimes', 'string', 'max:50'],
            'target_amount' => $this->isMethod('POST')
                ? $this->positiveMoneyRules()
                : array_merge(['sometimes'], array_slice($this->positiveMoneyRules(), 1)),
            // Only set directly when creating a goal that already holds money.
            'current_amount' => $this->moneyRules(false),
            'monthly_target' => $this->moneyRules(false),
            'allocation_type' => ['sometimes', Rule::in(AllocationType::values())],
            'allocation_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'target_date' => ['nullable', 'date', 'after:today'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'status' => ['sometimes', Rule::in(['active', 'reached', 'paused', 'archived'])],
        ];
    }
}
