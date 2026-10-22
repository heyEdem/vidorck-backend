<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminVariantStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-catalogue');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:255'], 'productCode' => ['required', 'string', 'max:255'],
            'sourcePriceMinor' => ['required_without:priceMinor', 'nullable', 'integer', 'between:0,1000000000'],
            'priceMinor' => ['required_without:sourcePriceMinor', 'integer', 'between:0,1000000000'],
            'isActive' => ['sometimes', 'boolean'], 'displayOrder' => ['sometimes', 'integer', 'between:0,1000000']];
    }
}
