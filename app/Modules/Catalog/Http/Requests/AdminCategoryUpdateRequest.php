<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-catalogue');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:255'], 'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'], 'displayOrder' => ['sometimes', 'integer', 'between:0,1000000'], 'isActive' => ['sometimes', 'boolean']];
    }
}
