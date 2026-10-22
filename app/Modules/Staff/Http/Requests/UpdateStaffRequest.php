<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Enums\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('staff'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required_without:isActive', Rule::enum(StaffRole::class)],
            'isActive' => ['required_without:role', 'boolean'],
        ];
    }
}
