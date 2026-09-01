<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Checked as an image, not just by extension. The 2 MB cap sits
            // under the post_max_size a shared host typically allows, so an
            // oversized file is refused with a clear message rather than
            // arriving as an empty request.
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.max' => 'Pictures must be 2 MB or smaller.',
            'avatar.dimensions' => 'That image is too small — use one at least 64×64.',
        ];
    }
}
