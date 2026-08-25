<?php

namespace App\Http\Requests;

use App\Enums\SurplusAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CycleSurplusRequest extends FormRequest
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
            // An empty list is valid: it means "leave it in the bank".
            'allocations' => ['present', 'array', 'max:20'],
            'allocations.*.type' => ['required', Rule::in(SurplusAction::values())],
            'allocations.*.amount' => $this->positiveMoneyRules(),
            'allocations.*.debt_id' => [
                'required_if:allocations.*.type,debt',
                'nullable',
                Rule::exists('debts', 'id')->where('user_id', $userId),
            ],
            'allocations.*.savings_goal_id' => [
                'required_if:allocations.*.type,savings',
                'nullable',
                Rule::exists('savings_goals', 'id')->where('user_id', $userId),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->input('allocations', []) as $index => $row) {
                    $type = $row['type'] ?? null;

                    if ($type === SurplusAction::Debt->value && empty($row['debt_id'])) {
                        $validator->errors()->add("allocations.{$index}.debt_id", 'Choose which debt to pay.');
                    }

                    if ($type === SurplusAction::Savings->value && empty($row['savings_goal_id'])) {
                        $validator->errors()->add("allocations.{$index}.savings_goal_id", 'Choose which goal to add to.');
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'allocations.present' => 'Tell us what to do with the leftover money.',
        ];
    }
}
