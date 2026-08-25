<?php

namespace App\Http\Requests;

use App\Enums\IncomeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IncomeTransactionRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('income', 'update');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'amount' => $this->isMethod('POST')
                ? $this->positiveMoneyRules()
                : array_merge(['sometimes'], array_slice($this->positiveMoneyRules(), 1)),
            'status' => ['sometimes', Rule::in(IncomeStatus::values())],
            'income_source_id' => [
                'sometimes',
                'nullable',
                Rule::exists('income_sources', 'id')->where('user_id', $this->user()->id),
            ],
            // When it landed. Required only once it actually has.
            'received_date' => [$required, 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'type' => ['sometimes', Rule::in(['base', 'extra'])],
            'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $status = $this->input('status', IncomeStatus::Received->value);

                if ($status === IncomeStatus::Received->value && ! $this->filled('received_date')) {
                    $validator->errors()->add('received_date', 'When did this money arrive?');
                }
            },
        ];
    }
}
