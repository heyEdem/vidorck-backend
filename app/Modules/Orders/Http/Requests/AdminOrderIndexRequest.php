<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderSource;
use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-orders');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'source' => ['sometimes', Rule::enum(OrderSource::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
