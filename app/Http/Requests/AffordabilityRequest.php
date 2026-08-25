<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AffordabilityRequest extends FormRequest
{
    use MoneyRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => $this->positiveMoneyRules(),
        ];
    }
}
