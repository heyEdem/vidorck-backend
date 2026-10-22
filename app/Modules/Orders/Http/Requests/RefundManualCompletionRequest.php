<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RefundManualCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('process-refunds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['MOMO', 'CARD', 'CASH_OR_OTHER'])],
            'reference' => ['required', 'string', 'min:3', 'max:100'],
            'amountMinor' => ['required', 'integer', 'min:1'],
        ];
    }
}
