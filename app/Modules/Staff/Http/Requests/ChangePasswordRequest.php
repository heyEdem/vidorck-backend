<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Support\StaffPassword;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'max:128'],
            'newPassword' => [...StaffPassword::rules(), 'confirmed:newPasswordConfirmation', 'different:currentPassword'],
        ];
    }
}
