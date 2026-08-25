<?php

namespace App\Http\Requests;

use App\Enums\AllocationType;
use App\Enums\CycleAnchor;
use App\Enums\DebtType;
use App\Enums\Frequency;
use App\Enums\FundingMethod;
use App\Enums\IncomeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole onboarding wizard submitted in one go, so a half-finished setup
 * never leaves the account in an inconsistent state.
 */
class OnboardingRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Step 1 — how the user earns
            'income_mode' => ['required', Rule::in(IncomeMode::values())],
            'cycle_anchor' => ['sometimes', 'nullable', Rule::in(CycleAnchor::values())],
            'funding_method' => ['sometimes', 'nullable', Rule::in(FundingMethod::values())],

            // Step 2 — the figures that mode needs
            'base_salary' => $this->moneyRules(false),
            'target_draw' => $this->moneyRules(false),
            'cycle_start_day' => ['required', 'integer', 'min:1', 'max:31'],
            'forecast_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'forecast_factor' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:100'],
            'has_extra_income' => ['sometimes', 'boolean'],
            'default_buffer' => $this->moneyRules(false),

            // Step 2 — recurring expenses
            'recurring' => ['sometimes', 'array', 'max:50'],
            'recurring.*.name' => ['required', 'string', 'max:80'],
            'recurring.*.amount' => $this->positiveMoneyRules(),
            'recurring.*.minimum_amount' => $this->moneyRules(false),
            'recurring.*.maximum_amount' => $this->moneyRules(false),
            'recurring.*.category_name' => ['nullable', 'string', 'max:60'],
            'recurring.*.frequency' => ['required', Rule::in(Frequency::values())],
            'recurring.*.due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recurring.*.day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'recurring.*.interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            // Step 3 — debts
            'debts' => ['sometimes', 'array', 'max:20'],
            'debts.*.name' => ['required', 'string', 'max:80'],
            'debts.*.type' => ['required', Rule::in(DebtType::values())],
            'debts.*.current_balance' => $this->moneyRules(),
            'debts.*.original_amount' => $this->moneyRules(false),
            'debts.*.credit_limit' => $this->moneyRules(false),
            'debts.*.interest_rate' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'debts.*.minimum_payment' => $this->moneyRules(false),
            'debts.*.planned_payment' => $this->moneyRules(false),
            'debts.*.due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'debts.*.remaining_installments' => ['nullable', 'integer', 'min:0', 'max:600'],

            // Step 4 — savings
            'savings_goals' => ['sometimes', 'array', 'max:20'],
            'savings_goals.*.name' => ['required', 'string', 'max:80'],
            'savings_goals.*.target_amount' => $this->positiveMoneyRules(),
            'savings_goals.*.current_amount' => $this->moneyRules(false),
            'savings_goals.*.monthly_target' => $this->moneyRules(false),
            'savings_goals.*.allocation_type' => ['sometimes', Rule::in(AllocationType::values())],
            'savings_goals.*.allocation_value' => ['nullable', 'numeric', 'min:0'],
            'savings_goals.*.target_date' => ['nullable', 'date', 'after:today'],
            'savings_goals.*.priority' => ['sometimes', 'integer', 'min:1', 'max:5'],
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

                // Only ask for the figures the chosen mode actually needs.
                if ($mode->hasSalary() && ! $this->filled('base_salary')) {
                    $validator->errors()->add('base_salary', 'Enter the salary you receive.');
                }

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
