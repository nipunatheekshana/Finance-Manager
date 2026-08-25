<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DebtPaymentRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('debt', 'update');
    }

    public function rules(): array
    {
        return [
            'amount' => $this->positiveMoneyRules(),
            'payment_date' => ['sometimes', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            // Leave blank to let the app estimate from the configured rate.
            'interest_amount' => $this->moneyRules(false),
            'reduce_installment' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
