<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('expense', 'update');
    }

    public function rules(): array
    {
        $userId = $this->user()->id;
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'amount' => $this->isMethod('POST')
                ? $this->positiveMoneyRules()
                : array_merge(['sometimes'], array_slice($this->positiveMoneyRules(), 1)),

            // Ownership is enforced in the rule itself, never taken on trust.
            'category_id' => [
                $required,
                Rule::exists('categories', 'id')->where('user_id', $userId),
            ],
            'payment_method_id' => [
                $required,
                Rule::exists('payment_methods', 'id')->where('user_id', $userId),
            ],
            'debt_id' => [
                'nullable',
                Rule::exists('debts', 'id')->where('user_id', $userId),
            ],
            'recurring_transaction_id' => [
                'nullable',
                Rule::exists('recurring_transactions', 'id')->where('user_id', $userId),
            ],
            'expense_date' => ['sometimes', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'description' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'category_id.exists' => 'Choose a category from your list.',
            'payment_method_id.exists' => 'Choose a payment method from your list.',
            'expense_date.before_or_equal' => 'You cannot log an expense that far in the future.',
        ];
    }
}
