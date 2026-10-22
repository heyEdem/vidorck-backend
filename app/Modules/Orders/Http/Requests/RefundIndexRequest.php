<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RefundIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-refunds');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('orderNumber'))) {
            $this->merge(['orderNumber' => strtoupper(trim($this->input('orderNumber')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(RefundStatus::class)],
            'needsReconciliation' => ['sometimes', 'in:0,1,true,false'],
            'open' => ['sometimes', 'in:0,1,true,false'],
            'orderNumber' => ['sometimes', 'string', 'min:1', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
