<?php

namespace App\Http\Requests;

use App\Models\PlanFixedExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanFixedExpenseRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('monthlyPlan', 'update');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'amount' => $this->moneyRules(false),
            // Recorded when the bill turns out to differ from the estimate.
            'actual_amount' => $this->moneyRules(false),
            'status' => ['sometimes', Rule::in(PlanFixedExpense::STATUSES)],
            'due_date' => ['nullable', 'date'],
            'postponed_to' => ['nullable', 'date'],
        ];
    }
}
