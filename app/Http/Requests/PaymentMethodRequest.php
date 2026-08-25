<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentMethodRequest extends FormRequest
{
    use AuthorizesRouteModel;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('paymentMethod', 'update');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $methodId = $this->route('payment_method')?->id;

        return [
            'name' => [
                $required,
                'string',
                'max:60',
                Rule::unique('payment_methods', 'name')
                    ->where('user_id', $this->user()->id)
                    ->ignore($methodId),
            ],
            'type' => ['sometimes', 'string', Rule::in(['cash', 'bank', 'debit_card', 'credit_card', 'bnpl', 'other'])],
            'icon' => ['sometimes', 'string', 'max:50'],
            // Linking to a debt makes spending on this method raise that balance.
            'debt_id' => ['nullable', Rule::exists('debts', 'id')->where('user_id', $this->user()->id)],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
