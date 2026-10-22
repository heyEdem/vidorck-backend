<?php

namespace App\Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminDeliveryAreaUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-catalogue');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'aliases' => ['sometimes', 'array', 'max:20'],
            'aliases.*' => ['string', 'min:2', 'max:100', 'distinct:ignore_case'],
            'isActive' => ['sometimes', 'boolean'],
            'displayOrder' => ['sometimes', 'integer', 'between:0,1000000'],
        ];
    }
}
