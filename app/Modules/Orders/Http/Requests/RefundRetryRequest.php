<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class RefundRetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('decide-refunds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
