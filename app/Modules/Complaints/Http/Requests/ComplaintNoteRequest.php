<?php

namespace App\Modules\Complaints\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ComplaintNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('handle-complaints');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:1', 'max:2000']];
    }
}
