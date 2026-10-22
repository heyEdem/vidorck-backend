<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\SettlementMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Admin spec §7a: the complete new line list. Kept lines by `itemId`; new lines by `slug` + `productCode`. */
final class OrderContentsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('edit-order-contents');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
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
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:itemId,slug,productCode,quantity,expectedUnitPriceMinor'],
            'items.*.itemId' => ['sometimes', 'integer', 'min:1', 'distinct:strict', $strictInt],
            'items.*.slug' => ['required_without:items.*.itemId', 'string', 'max:160'],
            'items.*.productCode' => ['required_without:items.*.itemId', 'string', 'max:80'],
            'items.*.expectedUnitPriceMinor' => ['sometimes', 'integer', 'min:0', $strictInt],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20', $strictInt],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'settlement' => ['required', 'array:method,note'],
            'settlement.method' => ['required', Rule::enum(SettlementMethod::class)],
            'settlement.note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'basedOnRevision' => ['required', 'integer', 'min:0', $strictInt],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! is_array($this->input('items'))) {
                return;
            }
            $seen = [];
            foreach ($this->input('items') as $index => $item) {
                if (isset($item['itemId']) && (isset($item['slug']) || isset($item['productCode']))) {
                    $validator->errors()->add("items.$index", 'Send either an existing line (itemId) or a new product (slug and productCode), not both.');

                    continue;
                }
                if (! isset($item['itemId'])) {
                    $key = mb_strtolower($item['slug'].'|'.$item['productCode']);
                    if (isset($seen[$key])) {
                        $validator->errors()->add("items.$index", 'This product is already in the list.');
                    }
                    $seen[$key] = true;
                }
            }
        }];
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->validated('items'));
    }

    public function settlementMethod(): SettlementMethod
    {
        return SettlementMethod::from($this->string('settlement.method')->toString());
    }

    public function settlementNote(): ?string
    {
        $note = $this->input('settlement.note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
