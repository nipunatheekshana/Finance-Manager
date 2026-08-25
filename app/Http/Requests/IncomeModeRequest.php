<?php

namespace App\Http\Requests;

use App\Enums\CycleAnchor;
use App\Enums\FundingMethod;
use App\Enums\IncomeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IncomeModeRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'income_mode' => ['required', Rule::in(IncomeMode::values())],
            'cycle_anchor' => ['sometimes', 'nullable', Rule::in(CycleAnchor::values())],
            'funding_method' => ['sometimes', 'nullable', Rule::in(FundingMethod::values())],
            'cycle_start_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'base_salary' => $this->moneyRules(false),
            'target_draw' => $this->moneyRules(false),
            'forecast_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'forecast_factor' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $mode = IncomeMode::tryFrom((string) $this->input('income_mode'));

                if ($mode === null) {
                    return;
                }

                $funding = $this->filled('funding_method')
                    ? FundingMethod::from($this->input('funding_method'))
                    : $mode->defaultFundingMethod();

                // A salary-funded plan needs a salary to be funded by.
                if ($mode->hasSalary() && ! $this->filled('base_salary')) {
                    $validator->errors()->add('base_salary', 'Enter the salary you receive.');
                }

                // A draw-funded plan needs a draw.
                if ($funding->usesHoldingPot() && ! $this->filled('target_draw')) {
                    $validator->errors()->add(
                        'target_draw',
                        'Enter the amount you want to pay yourself each cycle.',
                    );
                }
            },
        ];
    }
}
