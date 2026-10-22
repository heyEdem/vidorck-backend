<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ManualOrderRequest extends FormRequest
{
    public const METHODS = ['MOMO', 'CARD', 'CASH_OR_OTHER'];

    public function authorize(): bool
    {
        return Gate::allows('create-manual-orders');
    }

    protected function prepareForValidation(): void
    {
        foreach (['fullName', 'phone', 'region', 'area', 'address', 'landmark', 'ghanaPostGps', 'paymentReference', 'note'] as $field) {
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
        $strictInt = static function (string $attribute, mixed $value, $fail): void {
            if (! is_int($value)) {
                $fail('The value must be an integer.');
            }
        };

        return [
            // Manual orders only ever enter at PAID (website-spec §15); any other requested status is rejected.
            'status' => ['sometimes', Rule::in(['PAID'])],
            'confirm' => ['required', static function (string $attribute, mixed $value, $fail): void {
                if ($value !== true) {
                    $fail('Confirm that payment has already been received.');
                }
            }],
            'fullName' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^(?:\\+233|0)[235][0-9]{8}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'region' => ['required', 'string', 'min:2', 'max:80'],
            'area' => ['required', 'string', 'min:2', 'max:120'],
            'address' => ['required', 'string', 'min:5', 'max:200'],
            'landmark' => ['sometimes', 'nullable', 'string', 'min:2', 'max:160'],
            'ghanaPostGps' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}-[0-9]{3,4}-[0-9]{4}$/i'],
            'specialArrangement' => ['sometimes', 'boolean'],
            'paymentMethod' => ['required', Rule::in(self::METHODS)],
            'paymentReference' => ['required', 'string', 'min:3', 'max:100'],
            'amountPaidMinor' => ['required', 'integer', 'min:1', $strictInt],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.slug' => ['required', 'string', 'max:160'],
            'items.*.productCode' => ['required', 'string', 'max:80'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20', $strictInt],
            'items.*.lineId' => ['sometimes', 'nullable', 'string', 'max:40', 'distinct:strict'],
            'items.*.parentLineId' => ['sometimes', 'nullable', 'string', 'max:40'],
            'items.*.expectedUnitPriceMinor' => ['sometimes', 'integer', 'min:0', $strictInt],
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
                    $validator->errors()->add("items.$index.parentLineId", 'Choose a valid bundle line in this order.');
                }
            }
        }];
    }
}
