<?php

namespace App\Http\Requests;

use App\Enums\DebtType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DebtRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('debt', 'update');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'type' => [$required, Rule::in(DebtType::values())],
            'original_amount' => $this->moneyRules(false),
            'current_balance' => $this->isMethod('POST')
                ? $this->moneyRules()
                : array_merge(['sometimes'], array_slice($this->moneyRules(), 1)),
            'credit_limit' => $this->moneyRules(false),
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'minimum_payment' => $this->moneyRules(false),
            'planned_payment' => $this->moneyRules(false),
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'remaining_installments' => ['nullable', 'integer', 'min:0', 'max:600'],
            'installment_amount' => $this->moneyRules(false),
            'early_settlement_amount' => $this->moneyRules(false),
            'status' => ['sometimes', Rule::in(['active', 'paid_off', 'closed'])],
        ];
    }

    public function messages(): array
    {
        return [
            'interest_rate.max' => 'Enter the annual interest rate as a percentage, for example 28.',
        ];
    }
}
