<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-catalogue');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [...$this->commonRules(), 'name' => ['required', 'string', 'max:255'], 'categoryId' => ['required', 'integer', 'min:1'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'hairTypeIds' => ['sometimes', 'array'], 'hairTypeIds.*' => ['integer', 'distinct', 'min:1'],
            'variants' => ['sometimes', 'array'], 'variants.*.label' => ['required', 'string', 'max:255'],
            'variants.*.productCode' => ['required', 'string', 'max:255'],
            'variants.*.sourcePriceMinor' => ['required_without:variants.*.priceMinor', 'nullable', 'integer', 'between:0,1000000000'],
            'variants.*.priceMinor' => ['required_without:variants.*.sourcePriceMinor', 'integer', 'between:0,1000000000'],
            'variants.*.isActive' => ['sometimes', 'boolean'], 'variants.*.displayOrder' => ['sometimes', 'integer', 'between:0,1000000']];
    }

    /** @return array<string,list<string>> */
    private function commonRules(): array
    {
        return ['displayOrder' => ['sometimes', 'integer', 'between:0,1000000'], 'isPublished' => ['sometimes', 'boolean'],
            'isNewLook' => ['sometimes', 'boolean'], 'imageAlt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imagePosition' => ['sometimes', 'string', 'max:100']];
    }
}
