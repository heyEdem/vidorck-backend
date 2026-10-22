<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Actions\BuildDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-dashboard');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = CarbonImmutable::now(BuildDashboard::TIMEZONE)->toDateString();

        return [
            'period' => ['sometimes', Rule::in(['today', 'week', 'month', 'year', 'custom'])],
            'from' => ['required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d', "before_or_equal:{$today}"],
            'to' => ['required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d', 'after_or_equal:from', "before_or_equal:{$today}"],
        ];
    }
}
