<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpensePreviewRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => $this->positiveMoneyRules(),
            'expense_date' => ['sometimes', 'nullable', 'date'],
            'category_id' => [
                'sometimes',
                'nullable',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->id),
            ],
            // Set when editing, so the expense's current amount is not counted twice.
            'expense_id' => [
                'sometimes',
                'nullable',
                Rule::exists('expenses', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
