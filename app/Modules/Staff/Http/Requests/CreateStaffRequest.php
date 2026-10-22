<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', StaffUser::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:staff_users,email'],
            'role' => ['required', Rule::enum(StaffRole::class)],
            'password' => ['prohibited'],
        ];
    }
}
