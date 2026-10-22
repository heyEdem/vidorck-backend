<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-catalogue');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:255'], 'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'categoryId' => ['sometimes', 'required', 'integer', 'min:1'], 'hairTypeIds' => ['sometimes', 'array'],
            'hairTypeIds.*' => ['integer', 'distinct', 'min:1'], 'displayOrder' => ['sometimes', 'integer', 'between:0,1000000'],
            'isPublished' => ['sometimes', 'boolean'], 'isNewLook' => ['sometimes', 'boolean'], 'imageAlt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imagePosition' => ['sometimes', 'string', 'max:100']];
    }
}
