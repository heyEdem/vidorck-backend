<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-images');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'max:8192'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
