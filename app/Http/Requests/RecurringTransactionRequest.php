<?php

namespace App\Http\Requests;

use App\Enums\AmountType;
use App\Enums\Frequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecurringTransactionRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('recurringTransaction', 'update');
    }

    public function rules(): array
    {
        $userId = $this->user()->id;
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            // The expected amount, used for planning.
            'amount' => $this->isMethod('POST')
                ? $this->positiveMoneyRules()
                : array_merge(['sometimes'], array_slice($this->positiveMoneyRules(), 1)),
            'minimum_amount' => $this->moneyRules(false),
            'maximum_amount' => $this->moneyRules(false),
            'amount_type' => ['sometimes', Rule::in(AmountType::values())],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')->where('user_id', $userId)],
            'frequency' => [$required, Rule::in(Frequency::values())],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'start_date' => [$required, 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $min = $this->input('minimum_amount');
                $max = $this->input('maximum_amount');
                $expected = $this->input('amount');

                if ($min !== null && $max !== null && (float) $min > (float) $max) {
                    $validator->errors()->add('minimum_amount', 'The minimum cannot be more than the maximum.');
                }

                // The planning figure has to sit inside the stated range.
                if ($expected !== null && $min !== null && (float) $expected < (float) $min) {
                    $validator->errors()->add('amount', 'The expected amount cannot be below the minimum.');
                }

                if ($expected !== null && $max !== null && (float) $expected > (float) $max) {
                    $validator->errors()->add('amount', 'The expected amount cannot be above the maximum.');
                }

                if ($this->input('frequency') === Frequency::Custom->value && ! $this->filled('interval_days')) {
                    $validator->errors()->add('interval_days', 'Tell us how many days apart this repeats.');
                }
            },
        ];
    }
}
