<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TrackOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Phone shape is deliberately not validated here: any non-match is the generic 404.
        return [
            'orderNumber' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }
}
