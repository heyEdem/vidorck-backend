<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

final class OrderDeliveryUpdateRequest extends FormRequest
{
    private const FIELDS = ['region', 'area', 'address', 'landmark', 'ghanaPostGps'];

    public function authorize(): bool
    {
        return Gate::allows('edit-order-delivery');
    }

    protected function prepareForValidation(): void
    {
        foreach ([...self::FIELDS, 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        if (is_string($this->input('ghanaPostGps'))) {
            $this->merge(['ghanaPostGps' => strtoupper($this->input('ghanaPostGps'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Same shapes as checkout (CheckoutRequest); every field is optional on edit.
        return [
            'region' => ['sometimes', 'string', 'min:2', 'max:80'],
            'area' => ['sometimes', 'string', 'min:2', 'max:120'],
            'address' => ['sometimes', 'string', 'min:5', 'max:200'],
            'landmark' => ['sometimes', 'string', 'min:2', 'max:160'],
            'ghanaPostGps' => ['sometimes', 'string', 'regex:/^[A-Z]{2}-[0-9]{3,4}-[0-9]{4}$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->only(self::FIELDS) === []) {
                $validator->errors()->add('delivery', 'Provide at least one delivery field to change.');
            }
        }];
    }

    /** @return array<string, string> */
    public function changes(): array
    {
        /** @var array<string, string> */
        return $this->safe()->only(self::FIELDS);
    }
}
