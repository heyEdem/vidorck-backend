<?php

namespace App\Modules\Complaints\Http\Requests;

use App\Modules\Complaints\Enums\ComplaintStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminComplaintIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-complaints');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['sometimes', Rule::enum(ComplaintStatus::class)], 'mine' => ['sometimes', 'boolean'], 'unassigned' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'between:1,100']];
    }
}
