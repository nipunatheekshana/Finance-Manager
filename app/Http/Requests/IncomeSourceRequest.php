<?php

namespace App\Http\Requests;

use App\Enums\IncomeCadence;
use App\Enums\IncomeSourceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncomeSourceRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('incomeSource', 'update');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'kind' => ['sometimes', Rule::in(IncomeSourceKind::values())],
            'cadence' => ['sometimes', Rule::in(IncomeCadence::values())],
            'client_name' => ['nullable', 'string', 'max:120'],
            'expected_amount' => $this->moneyRules(false),
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
