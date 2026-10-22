<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class OrderTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update-order-status');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'toStatus' => ['required', Rule::enum(OrderStatus::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
