<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class RefundNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('add-refund-notes');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:1', 'max:2000']];
    }
}
