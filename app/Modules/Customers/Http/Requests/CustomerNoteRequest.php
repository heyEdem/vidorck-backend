<?php

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class CustomerNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('add-customer-notes');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:1', 'max:2000']];
    }
}
