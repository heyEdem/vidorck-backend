<?php

namespace App\Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['fullName', 'phone', 'region', 'area', 'address', 'landmark', 'ghanaPostGps'] as $field) {
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
        return [
            'fullName' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^(?:\\+233|0)[235][0-9]{8}$/'],
            'email' => ['required', 'email', 'max:254'],
            'region' => ['required', 'string', 'min:2', 'max:80'],
            'area' => ['required', 'string', 'min:2'],
            'address' => ['required', 'string', 'min:5', 'max:200'],
            'landmark' => ['required', 'string', 'min:2', 'max:160'],
            'ghanaPostGps' => ['required', 'string', 'regex:/^[A-Z]{2}-[0-9]{3,4}-[0-9]{4}$/i'],
            'policyAccepted' => ['required', function (string $attribute, mixed $value, $fail): void {
                if ($value !== true) {
                    $fail('Accept the policy to continue.');
                }
            }],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.slug' => ['required', 'string', 'max:160'],
            'items.*.productCode' => ['required', 'string', 'max:80'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20', function (string $attribute, mixed $value, $fail): void {
                if (! is_int($value)) {
                    $fail('The quantity must be an integer.');
                }
            }],
            'items.*.lineId' => ['sometimes', 'nullable', 'string', 'max:40', 'distinct:strict'],
            'items.*.parentLineId' => ['sometimes', 'nullable', 'string', 'max:40'],
            'items.*.expectedUnitPriceMinor' => ['sometimes', 'integer', 'min:0', function (string $attribute, mixed $value, $fail): void {
                if (! is_int($value)) {
                    $fail('The expected price must be an integer.');
                }
            }],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! is_array($this->input('items'))) {
                return;
            }
            $ids = [];
            foreach ($this->input('items') as $index => $item) {
                if (is_string($item['lineId'] ?? null)) {
                    $ids[$item['lineId']] = $index;
                }
            }
            foreach ($this->input('items') as $index => $item) {
                $parent = $item['parentLineId'] ?? null;
                if ($parent !== null && (! is_string($parent) || ! isset($ids[$parent]) || $ids[$parent] === $index)) {
                    $validator->errors()->add("items.$index.parentLineId", 'Choose a valid bundle line in this cart.');
                }
            }
        }];
    }
}
