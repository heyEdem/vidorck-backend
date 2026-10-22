<?php

namespace App\Modules\Complaints\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ComplaintStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('handle-complaints');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['toStatus' => ['required', Rule::in(['IN_PROGRESS', 'RESOLVED'])], 'note' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
