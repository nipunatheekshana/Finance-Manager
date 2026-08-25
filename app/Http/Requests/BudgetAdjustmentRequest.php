<?php

namespace App\Http\Requests;

use App\Enums\AdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetAdjustmentRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('weeklyBudget', 'update');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(AdjustmentType::values())],
            // Defaults to the full overspend when omitted.
            'amount' => $this->moneyRules(false),
            'category_id' => [
                'required_if:type,category',
                'nullable',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->id),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required_if' => 'Choose which category budget to reduce.',
        ];
    }
}
