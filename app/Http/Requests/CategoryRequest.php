<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    use AuthorizesRouteModel, MoneyRules;

    public function authorize(): bool
    {
        return $this->allowsRouteModel('category', 'update');
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $categoryId = $this->route('category')?->id;

        return [
            'name' => [
                $required,
                'string',
                'max:60',
                Rule::unique('categories', 'name')
                    ->where('user_id', $this->user()->id)
                    ->ignore($categoryId),
            ],
            'icon' => ['sometimes', 'string', 'max:50'],
            'color' => ['sometimes', 'string', 'max:20'],
            'monthly_budget' => $this->moneyRules(false),
            // Reserve this budget in the plan instead of only warning on it.
            'is_allowance' => ['sometimes', 'boolean'],
            'weekly_budget' => $this->moneyRules(false),
            'warning_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a category with that name.',
        ];
    }
}
