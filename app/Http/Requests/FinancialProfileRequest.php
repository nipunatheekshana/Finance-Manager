<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinancialProfileRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_salary' => $this->moneyRules(false),
            'salary_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'has_extra_income' => ['sometimes', 'boolean'],
            'default_buffer' => $this->moneyRules(false),
            'extra_debt_percentage' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'extra_savings_percentage' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'extra_spending_percentage' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'notification_settings' => ['sometimes', 'array'],
            'notification_settings.*' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $keys = ['extra_debt_percentage', 'extra_savings_percentage', 'extra_spending_percentage'];

                // Only validate the split when the request touches it at all.
                if (! collect($keys)->contains(fn (string $key) => $this->has($key))) {
                    return;
                }

                $profile = $this->user()->financialProfile;

                $total = collect($keys)->sum(
                    fn (string $key) => (int) $this->input($key, $profile?->{$key} ?? 0)
                );

                if ($total !== 100) {
                    $validator->errors()->add(
                        'extra_debt_percentage',
                        'The extra-income split must add up to 100%. It currently adds up to '.$total.'%.'
                    );
                }
            },
        ];
    }
}
