<?php

namespace App\Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdminDeliveryAreaIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-catalogue');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
