<?php

namespace App\Http\Requests;

use App\Services\PlanCommitmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TopUpAllowanceRequest extends FormRequest
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
            'amount' => $this->moneyRules(),
            'source' => ['required', Rule::in(PlanCommitmentService::TOP_UP_SOURCES)],
            // Only when the money comes from another allowance, and only ever
            // one of the caller's own categories.
            'from_category_id' => [
                'required_if:source,allowance',
                'nullable',
                Rule::exists('categories', 'id')->where('user_id', $userId),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
