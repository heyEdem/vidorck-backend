<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class RefundDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('decide-refunds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'reason' => [$this->input('decision') === 'REJECT' ? 'required' : 'sometimes', 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
