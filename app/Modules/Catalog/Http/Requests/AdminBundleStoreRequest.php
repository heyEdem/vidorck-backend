<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminBundleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-catalogue');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'productCode' => ['required', 'string', 'max:255'], 'eyebrow' => ['sometimes', 'nullable', 'string', 'max:255'],
            'priceMinor' => ['required', 'integer', 'between:0,1000000000'], 'isPublished' => ['sometimes', 'boolean'],
            'displayOrder' => ['sometimes', 'integer', 'between:0,1000000'], 'imageAlt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items' => ['required', 'list', 'min:1'], 'items.*.variantId' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'between:1,1000000'], 'items.*.displayOrder' => ['sometimes', 'integer', 'between:0,1000000']];
    }
}
