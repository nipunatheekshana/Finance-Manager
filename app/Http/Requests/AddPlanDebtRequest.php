<?php

namespace App\Http\Requests;

use App\Services\PlanCommitmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPlanDebtRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        // Both models are checked before validation, so another account's ids
        // get "forbidden" rather than a 422 that confirms they exist.
        return $this->allowsRouteModel('monthlyPlan', 'update')
            && $this->allowsRouteModel('debt', 'update');
    }

    public function rules(): array
    {
        return [
            'amount' => $this->moneyRules(),
            'source' => ['required', Rule::in(PlanCommitmentService::SOURCES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
