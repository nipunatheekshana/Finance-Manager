<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Handles are compared and stored lowercase, so "Nipuna" and "nipuna"
        // are the same person rather than two accounts one typo apart.
        if ($this->has('handle') && is_string($this->input('handle'))) {
            $this->merge(['handle' => mb_strtolower(trim($this->input('handle')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:80'],
            'handle' => [
                'sometimes',
                'string',
                'min:3',
                'max:30',
                'regex:'.User::HANDLE_PATTERN,
                Rule::notIn(User::RESERVED_HANDLES),
                Rule::unique('users', 'handle')->ignore($this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'handle.regex' => 'Use letters, numbers, dots and underscores. Start and end with a letter or number.',
            'handle.not_in' => 'That handle is reserved. Try another.',
            'handle.unique' => 'That handle is taken.',
        ];
    }
}
