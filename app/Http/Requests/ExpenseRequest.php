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

            // Decided before the save, when the preview shows the pot running
            // out: where the excess comes from, so no banner ever has to fire.
            'allowance_top_up' => ['nullable', 'array'],
            'allowance_top_up.source' => [
                'required_with:allowance_top_up',
                Rule::in(\App\Services\PlanCommitmentService::TOP_UP_SOURCES),
            ],
            'allowance_top_up.amount' => ['required_with:allowance_top_up', 'numeric', 'gt:0', 'decimal:0,2'],
            'allowance_top_up.from_category_id' => [
                'nullable',
                'required_if:allowance_top_up.source,allowance',
                Rule::exists('categories', 'id')->where('user_id', $userId),
            ],
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
