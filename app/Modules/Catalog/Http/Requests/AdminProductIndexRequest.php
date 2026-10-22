<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-catalogue');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'], 'categoryId' => ['sometimes', 'integer', 'min:1'],
            'isPublished' => ['sometimes', 'boolean']];
    }
}
