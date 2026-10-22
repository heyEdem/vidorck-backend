<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminCategoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-catalogue');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
