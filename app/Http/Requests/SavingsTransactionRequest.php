<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavingsTransactionRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('savingsGoal', 'update');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['deposit', 'withdrawal', 'transfer'])],
            'amount' => $this->positiveMoneyRules(),
            'transaction_date' => ['sometimes', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'description' => ['nullable', 'string', 'max:255'],
            // Required only for transfers; the destination goal.
            'to_goal_id' => [
                'required_if:type,transfer',
                'nullable',
                Rule::exists('savings_goals', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'to_goal_id.required_if' => 'Choose which goal to transfer the money into.',
        ];
    }
}
