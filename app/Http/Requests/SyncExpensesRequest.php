<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncExpensesRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'expenses' => ['required', 'array', 'min:1', 'max:200'],
            'expenses.*.amount' => $this->positiveMoneyRules(),
            'expenses.*.category_id' => ['required', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'expenses.*.payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('user_id', $userId)],
            'expenses.*.expense_date' => ['required', 'date'],
            'expenses.*.description' => ['nullable', 'string', 'max:255'],
            // Required here: it is what makes replaying the offline queue safe.
            'expenses.*.client_uuid' => ['required', 'uuid'],
        ];
    }
}
