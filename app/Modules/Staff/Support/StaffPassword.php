<?php

namespace App\Modules\Staff\Support;

use Illuminate\Validation\Rules\Password;

final class StaffPassword
{
    /** @return list<Password|string> */
    public static function rules(): array
    {
        return ['required', 'string', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()];
    }
}
